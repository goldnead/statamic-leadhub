<?php

namespace Goldnead\Leadhub\Services\Import;

/**
 * Reads a CSV (or a TSV pasted from Excel) in chunks, by byte position.
 *
 * The position is what lets the import resume in the next queued job without
 * re-reading the rows before it. It is the offset `ftell()` reports after a
 * complete record, so a quoted cell that spans lines is never cut in half.
 */
class CsvReader
{
    public function __construct(
        protected string $path,
        protected string $delimiter = ',',
        protected string $encoding = 'UTF-8',
    ) {}

    public static function detectDelimiter(string $sample): string
    {
        $firstLine = strtok(ltrim($sample, "\xEF\xBB\xBF"), "\r\n") ?: '';
        $best = ',';
        $bestCount = 0;

        // Tab first: a spreadsheet paste is tab separated, and a name column
        // may well contain a comma.
        foreach (["\t", ';', ',', '|'] as $candidate) {
            $count = substr_count($firstLine, $candidate);

            if ($count > $bestCount) {
                $best = $candidate;
                $bestCount = $count;
            }
        }

        return $best;
    }

    public static function detectEncoding(string $sample): string
    {
        // A sample cut in the middle of a multibyte character is not invalid
        // UTF-8, so drop up to three trailing bytes before judging it.
        for ($cut = 0; $cut <= 3; $cut++) {
            if (mb_check_encoding(substr($sample, 0, strlen($sample) - $cut), 'UTF-8')) {
                return 'UTF-8';
            }
        }

        return 'Windows-1252';
    }

    /**
     * @return array{header: list<string>, position: int}
     */
    public function header(): array
    {
        $handle = $this->open();
        $cells = $this->record($handle, true);
        $position = (int) ftell($handle);
        fclose($handle);

        return ['header' => array_map(static fn ($c) => trim((string) $c), $cells ?? []), 'position' => $position];
    }

    /**
     * Read up to $limit data rows from $position. Blank lines are skipped and
     * do not count.
     *
     * @return array{rows: list<list<string>>, position: int, eof: bool}
     */
    public function read(int $position, int $limit): array
    {
        $handle = $this->open();
        fseek($handle, $position);
        $rows = [];

        while (count($rows) < $limit && ($cells = $this->record($handle)) !== null) {
            if ($cells === [null] || $cells === []) {
                continue;
            }

            $rows[] = array_map(static fn ($c) => trim((string) $c), $cells);
        }

        $end = (int) ftell($handle);
        $eof = feof($handle);

        if (! $eof) {
            // fgetcsv only flags the end once it tried to read past it.
            $peek = fgets($handle);
            $eof = $peek === false;
        }

        fclose($handle);

        return ['rows' => $rows, 'position' => $end, 'eof' => $eof];
    }

    /**
     * @param  resource  $handle
     * @return list<string|null>|null
     */
    protected function record($handle, bool $first = false): ?array
    {
        $cells = fgetcsv($handle, 0, $this->delimiter, '"', '');

        if ($cells === false) {
            return null;
        }

        if ($first && isset($cells[0])) {
            $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $cells[0]);
        }

        if ($this->encoding !== 'UTF-8') {
            $cells = array_map(
                fn ($c) => $c === null ? null : mb_convert_encoding($c, 'UTF-8', $this->encoding),
                $cells,
            );
        }

        return $cells;
    }

    /** @return resource */
    protected function open()
    {
        $handle = fopen($this->path, 'rb');

        if (! $handle) {
            throw new \RuntimeException('Could not open import file: '.$this->path);
        }

        return $handle;
    }
}
