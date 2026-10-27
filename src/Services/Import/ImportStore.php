<?php

namespace Goldnead\Leadhub\Services\Import;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Where an import lives between the upload and the last chunk.
 *
 * Files, not a table: the flat-file driver has no tables beyond settings, and
 * an import is a short-lived working set (the source file plus a small JSON
 * state) rather than data anyone queries. One folder per import on the disk
 * set under `leadhub.imports`.
 */
class ImportStore
{
    public function disk(): Filesystem
    {
        return Storage::disk((string) config('leadhub.imports.disk', 'local'));
    }

    protected function dir(string $id): string
    {
        return trim((string) config('leadhub.imports.directory', 'leadhub/imports'), '/').'/'.$id;
    }

    public function newId(): string
    {
        return (string) Str::uuid();
    }

    public function sourcePath(string $id): string
    {
        return $this->dir($id).'/source.csv';
    }

    /** Absolute path of the staged file, for fopen(). */
    public function absoluteSource(string $id): string
    {
        return $this->disk()->path($this->sourcePath($id));
    }

    public function putSource(string $id, string $tmpPath): void
    {
        $stream = fopen($tmpPath, 'rb');
        $this->disk()->put($this->sourcePath($id), $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        if (! preg_match('/^[0-9a-f-]{36}$/', $id)) {
            return null;
        }

        $file = $this->dir($id).'/state.json';

        if (! $this->disk()->exists($file)) {
            return null;
        }

        $state = json_decode((string) $this->disk()->get($file), true);

        return is_array($state) ? $state : null;
    }

    /** @param  array<string, mixed>  $state */
    public function save(array $state): void
    {
        $this->disk()->put($this->dir($state['id']).'/state.json', json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Imports of one brand, newest first. Staged files that were never run are
     * left out: they are an upload, not an import.
     *
     * @return list<array<string, mixed>>
     */
    public function history(int $brandId, int $limit = 25): array
    {
        $root = trim((string) config('leadhub.imports.directory', 'leadhub/imports'), '/');
        $states = [];

        foreach ($this->disk()->directories($root) as $directory) {
            $state = $this->find(basename($directory));

            if ($state && (int) $state['brand_id'] === $brandId && $state['status'] !== 'staged') {
                $states[] = $state;
            }
        }

        usort($states, static fn ($a, $b) => strcmp((string) $b['created_at'], (string) $a['created_at']));

        return array_slice($states, 0, $limit);
    }
}
