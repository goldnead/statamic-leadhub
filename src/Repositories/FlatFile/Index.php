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

    public function __construct(
        protected string $name,
        protected string $disk,
        protected string $directory,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    /** Path on the configured disk where this index file lives. */
    public function diskPath(): string
    {
        return trim($this->directory, '/').'/'.$this->name.'.json';
    }

    public function exists(): bool
    {
        return Storage::disk($this->disk)->exists($this->diskPath());
    }

    /** Load the index from disk. Returns an empty skeleton if it doesn't exist. */
    public function load(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

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
    }

    /** Forget the in-memory copy so the next call reloads from disk. */
    public function forget(): void
    {
        $this->data = null;
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
