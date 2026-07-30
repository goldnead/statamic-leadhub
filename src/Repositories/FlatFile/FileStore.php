<?php

namespace Goldnead\Leadhub\Repositories\FlatFile;

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * Low-level filesystem helper for the flat-file driver.
 *
 * Statamic ships with symfony/yaml — we use it directly rather than going
 * through Statamic\Facades\YAML to avoid a hard dependency on Statamic
 * facade resolution during boot/migration commands.
 */
class FileStore
{
    protected BrandSegments $segments;

    public function __construct(protected string $root, ?BrandSegments $segments = null)
    {
        $this->segments = $segments ?? new BrandSegments;
    }

    public function root(): string
    {
        return $this->root;
    }

    // ------------------------------------------------------------- paths

    /**
     * The absolute path this context **writes** to.
     *
     * Reads must not use this: on a multi-brand install that has not run
     * `leadhub:migrate-flat-brands` yet, the data is still in the pre-brand
     * root while writes already go to the brand directory.
     */
    public function path(string $relative): string
    {
        return $this->pathIn($this->segments->write(), $relative);
    }

    /** The absolute path inside one segment; `''` is the pre-brand root. */
    public function pathIn(string $segment, string $relative): string
    {
        $base = rtrim($this->root, '/');
        $prefix = $segment === '' ? $base : $base.'/'.$segment;

        return $prefix.'/'.ltrim($relative, '/');
    }

    /**
     * The first existing path across the readable segments, or null.
     *
     * Null means "not there", and — with multi-brand on and no current brand —
     * also "you may not look". Callers treat both the same way, which is the
     * point: a worker with no brand reads nothing rather than everything.
     */
    public function resolve(string $relative): ?string
    {
        foreach ($this->segments->read() ?? [] as $segment) {
            $candidate = $this->pathIn($segment, $relative);

            if (File::exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Where a write should land: the current segment, unless the file already
     * exists in a readable one.
     *
     * Without this an install that has not migrated yet would gain a second
     * copy in the brand directory, which then shadows the original — one
     * handle, two files, and an edit that silently stops being visible to the
     * thing that reads the old one.
     */
    protected function writePath(string $relative): string
    {
        if ($existing = $this->resolve($relative)) {
            return $existing;
        }

        return $this->path($relative);
    }

    // ------------------------------------------------------------- reads

    public function exists(string $relative): bool
    {
        return $this->resolve($relative) !== null;
    }

    public function ensureDirectory(string $relative): void
    {
        $abs = $this->path($relative);
        if (! File::isDirectory($abs)) {
            File::makeDirectory($abs, 0755, recursive: true);
        }
    }

    public function readYaml(string $relative): array
    {
        $path = $this->resolve($relative);

        if ($path === null) {
            return [];
        }

        $contents = File::get($path);
        $parsed = Yaml::parse($contents);

        return is_array($parsed) ? $parsed : [];
    }

    // ------------------------------------------------------------ writes

    public function writeYaml(string $relative, array $data): void
    {
        $path = $this->writePath($relative);
        $dir = dirname($path);

        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, recursive: true);
        }

        $yaml = Yaml::dump($data, inline: 4, indent: 2, flags: Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_OBJECT_AS_MAP);

        File::put($path, $yaml);
    }

    /**
     * Append a single line to a JSON-Lines file (one JSON object per line).
     */
    public function appendJsonLine(string $relative, array $data): void
    {
        $path = $this->writePath($relative);
        $dir = dirname($path);

        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, recursive: true);
        }

        $line = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($line === false) {
            throw new \RuntimeException('Failed to JSON-encode line for '.$relative);
        }

        File::append($path, $line."\n");
    }

    /**
     * Read all lines from a JSON-Lines file as an array of arrays.
     */
    public function readJsonLines(string $relative): array
    {
        $path = $this->resolve($relative);

        if ($path === null) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $records = [];

        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $records[] = $decoded;
            }
        }

        return $records;
    }

    public function delete(string $relative): bool
    {
        // Delete where the file actually is, not where a new one would go.
        $path = $this->resolve($relative);

        if ($path === null) {
            return false;
        }

        return File::delete($path);
    }

    public function deleteDirectory(string $relative): bool
    {
        $deleted = false;

        foreach ($this->segments->read() ?? [] as $segment) {
            $path = $this->pathIn($segment, $relative);

            if (File::isDirectory($path)) {
                $deleted = File::deleteDirectory($path) || $deleted;
            }
        }

        return $deleted;
    }

    /**
     * The newest mtime of a directory across the readable segments.
     *
     * The staleness check needs this rather than `mtime(path())`: before an
     * install has run `leadhub:migrate-flat-brands` the contacts still sit in
     * the pre-brand root while the write path already points at the brand
     * directory, which does not exist yet. `filemtime` on a missing directory
     * returns false, the index would never look stale, and it would never
     * rebuild.
     */
    public function directoryMtime(string $relative): ?int
    {
        $newest = null;

        foreach ($this->segments->read() ?? [] as $segment) {
            $mtime = @filemtime($this->pathIn($segment, $relative));

            if ($mtime !== false && ($newest === null || $mtime > $newest)) {
                $newest = $mtime;
            }
        }

        return $newest;
    }

    /** mtime of a file or null if it doesn't exist. */
    public function mtime(string $relative): ?int
    {
        $path = $this->resolve($relative);

        if ($path === null) {
            return null;
        }

        return File::lastModified($path);
    }

    /**
     * All files matching a glob pattern, as paths relative to the segment they
     * were found in — so a caller can hand the result straight back to
     * `readYaml()` and get the same file.
     *
     * The first segment wins: a migrated file shadows a copy that an
     * interrupted migration may have left behind in the old root.
     *
     * @return array<int, string>
     */
    public function glob(string $pattern): array
    {
        $found = [];

        foreach ($this->segments->read() ?? [] as $segment) {
            $prefix = $this->pathIn($segment, '');

            foreach (glob($this->pathIn($segment, $pattern)) ?: [] as $abs) {
                $relative = ltrim(str_replace($prefix, '', $abs), '/');

                if (! array_key_exists($relative, $found)) {
                    $found[$relative] = true;
                }
            }
        }

        return array_keys($found);
    }
}
