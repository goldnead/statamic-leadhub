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
    public function __construct(protected string $root)
    {
    }

    public function root(): string
    {
        return $this->root;
    }

    public function path(string $relative): string
    {
        return rtrim($this->root, '/').'/'.ltrim($relative, '/');
    }

    public function exists(string $relative): bool
    {
        return File::exists($this->path($relative));
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
        $path = $this->path($relative);

        if (! File::exists($path)) {
            return [];
        }

        $contents = File::get($path);
        $parsed = Yaml::parse($contents);

        return is_array($parsed) ? $parsed : [];
    }

    public function writeYaml(string $relative, array $data): void
    {
        $path = $this->path($relative);
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
        $path = $this->path($relative);
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
        $path = $this->path($relative);

        if (! File::exists($path)) {
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
        $path = $this->path($relative);
        if (! File::exists($path)) {
            return false;
        }

        return File::delete($path);
    }

    public function deleteDirectory(string $relative): bool
    {
        $path = $this->path($relative);
        if (! File::isDirectory($path)) {
            return false;
        }

        return File::deleteDirectory($path);
    }

    /** mtime of a file or null if it doesn't exist. */
    public function mtime(string $relative): ?int
    {
        $path = $this->path($relative);

        if (! File::exists($path)) {
            return null;
        }

        return File::lastModified($path);
    }

    /** All files matching a glob pattern, relative paths returned. */
    public function glob(string $pattern): array
    {
        $absPattern = $this->path($pattern);
        $matches = glob($absPattern) ?: [];

        return array_map(
            fn (string $abs) => ltrim(str_replace($this->root, '', $abs), '/'),
            $matches,
        );
    }
}
