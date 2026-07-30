<?php

namespace Goldnead\Leadhub\Repositories\FlatFile;

use Illuminate\Support\Facades\Storage;

/**
 * In-memory + on-disk JSON index for fast lookups against flat-file storage.
 *
 * Stache-style: the index lives on Laravel's "local" disk (or whatever is
 * configured in leadhub.storage.flat.index_disk), is rebuilt when the
 * underlying file mtimes drift, and survives across requests.
 */
class Index
{
    /** @var array<string, mixed>|null */
    protected ?array $data = null;

    /** The disk path `$data` was loaded from, so a brand switch invalidates it. */
    protected ?string $loadedFrom = null;

    protected BrandSegments $segments;

    public function __construct(
        protected string $name,
        protected string $disk,
        protected string $directory,
        ?BrandSegments $segments = null,
    ) {
        $this->segments = $segments ?? new BrandSegments;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * Path on the configured disk where this index file lives.
     *
     * The index is per brand, for the same reason the files are: it is a
     * materialised answer to "which contacts are there", and one shared index
     * would hand brand A's answer to brand B — with the flat store correctly
     * isolated underneath it, which is the worst version of that bug because
     * the data on disk would look right.
     *
     * Single-brand keeps the flat pre-1.11 path, so nothing moves and nothing
     * needs rebuilding on upgrade.
     */
    public function diskPath(): string
    {
        $dir = trim($this->directory, '/');
        $segment = $this->segments->write();

        return ($segment === '' ? $dir : $dir.'/'.$segment).'/'.$this->name.'.json';
    }

    public function exists(): bool
    {
        return Storage::disk($this->disk)->exists($this->diskPath());
    }

    /** Load the index from disk. Returns an empty skeleton if it doesn't exist. */
    public function load(): array
    {
        // The in-memory copy belongs to the brand it was loaded for. A single
        // process switches brands whenever something wraps work in
        // BrandContext::runFor() — a scheduled sweep, a migration, a test — and
        // without this the second brand would be served the first one's index
        // while its own files sat correctly isolated on disk. That is the worst
        // shape of this bug: the data would be right and the answer wrong.
        $path = $this->diskPath();

        if ($this->data !== null && $this->loadedFrom === $path) {
            return $this->data;
        }

        $this->loadedFrom = $path;

        if (! $this->exists()) {
            return $this->data = $this->skeleton();
        }

        $contents = Storage::disk($this->disk)->get($this->diskPath());
        $decoded = json_decode($contents, true);

        $this->data = is_array($decoded) ? $decoded : $this->skeleton();

        return $this->data;
    }

    public function save(): void
    {
        $payload = $this->data ?? $this->skeleton();
        $payload['rebuilt_at'] = time();

        Storage::disk($this->disk)->put(
            $this->diskPath(),
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        );
    }

    public function setData(array $data): void
    {
        $this->data = array_merge($this->skeleton(), $data);
    }

    public function getData(): array
    {
        return $this->load();
    }

    public function rebuiltAt(): ?int
    {
        return $this->load()['rebuilt_at'] ?? null;
    }

    public function items(): array
    {
        return $this->load()['items'] ?? [];
    }

    public function getItem(string $id): ?array
    {
        return $this->items()[$id] ?? null;
    }

    /** Look up a single id by indexed value. Returns null if no match. */
    public function lookup(string $bucket, string $value): ?string
    {
        $bucket = $this->load()['by_'.$bucket] ?? [];

        if (isset($bucket[$value])) {
            $entry = $bucket[$value];

            return is_array($entry) ? ($entry[0] ?? null) : $entry;
        }

        return null;
    }

    /** Look up all ids matching an indexed value. */
    public function lookupAll(string $bucket, string $value): array
    {
        $bucket = $this->load()['by_'.$bucket] ?? [];

        if (! isset($bucket[$value])) {
            return [];
        }

        $entry = $bucket[$value];

        return is_array($entry) ? $entry : [$entry];
    }

    public function delete(): void
    {
        if ($this->exists()) {
            Storage::disk($this->disk)->delete($this->diskPath());
        }
        $this->data = null;
        $this->loadedFrom = null;
    }

    /** Forget the in-memory copy so the next call reloads from disk. */
    public function forget(): void
    {
        $this->data = null;
        $this->loadedFrom = null;
    }

    protected function skeleton(): array
    {
        return [
            'version' => 1,
            'rebuilt_at' => null,
            'items' => [],
        ];
    }
}
