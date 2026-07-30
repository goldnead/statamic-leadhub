<?php

namespace Goldnead\Leadhub\Repositories\FlatFile;

/**
 * Where a brand's flat files live, and which of them a read may open.
 *
 * ## Brands live in the path, not in the file
 *
 * Under multi-brand every brand owns a directory and the entity paths sit
 * inside it:
 *
 *     content/leadhub/{brand}/contacts/{uuid}.yaml
 *     content/leadhub/{brand}/events/{uuid}.jsonl
 *     content/leadhub/{brand}/tags.yaml
 *
 * The alternative — a `brand:` key inside each YAML — was rejected, and the
 * reason is sharper here than anywhere else in the suite. A contact's filename
 * is its uuid, so listing one brand's contacts by key would mean opening every
 * other brand's file to discover it is not yours: an O(all brands) read for
 * every query on a driver whose whole point is that there is no database. And
 * a missing or misspelt key would fall through to the default brand, which is a
 * leak that reads like a typo.
 *
 * With a directory the isolation is structural: a brand's read never opens
 * another brand's file, and a file in the wrong place is visible in `ls` and in
 * a diff.
 *
 * ## The pre-brand layout keeps working
 *
 * Single-brand installs — the overwhelming majority, and every install before
 * 1.11 — keep writing to `content/leadhub/contacts/…` exactly as before. No
 * directory appears, nothing moves, there is nothing to do.
 *
 * Under multi-brand those files are read as the **default brand's**, and only
 * the default brand's, until `leadhub:migrate-flat-brands` moves them. They
 * were written before brands existed, so they belong to the brand every
 * existing row was backfilled onto — and to no other brand, ever. An install
 * that enables the flag must never open to an empty contact list.
 *
 * ## Fail closed
 *
 * Multi-brand with no current brand — a console run, a queue worker — resolves
 * to `null`: read nothing. Not everything. That matches the eloquent driver's
 * global scope, so the two drivers agree about the one case where guessing
 * would leak.
 */
class BrandSegments
{
    /**
     * Memoised per brand identity.
     *
     * These are consulted on every single file operation — one `readYaml()` per
     * contact — and the answer only changes when the current brand does. The
     * key carries the brand so a `BrandContext::runFor()` switch inside one
     * process invalidates it rather than serving the previous brand's path.
     *
     * @var array<string, array{write: string, read: array<int, string>|null}>
     */
    protected array $memo = [];

    /**
     * The directory new files are created in. `''` is the pre-1.11 root.
     */
    public function write(): string
    {
        return $this->memoised()['write'];
    }

    /**
     * Segments a read may look in, in order. `null` means: read nothing.
     *
     * @return array<int, string>|null
     */
    public function read(): ?array
    {
        return $this->memoised()['read'];
    }

    /** @return array{write: string, read: array<int, string>|null} */
    protected function memoised(): array
    {
        $manager = app('brand-context');

        $key = $manager->multiBrandEnabled()
            ? ($manager->hasCurrent() ? 'brand:'.$manager->currentId() : 'none')
            : 'single';

        return $this->memo[$key] ??= [
            'write' => $this->computeWrite(),
            'read' => $this->computeRead(),
        ];
    }

    /** Forget the memo — for tests, and for anything that renames a brand. */
    public function flush(): void
    {
        $this->memo = [];
    }

    protected function computeWrite(): string
    {
        $manager = app('brand-context');

        // Single-brand keeps the layout it has always had, so an install that
        // never enables multi-brand never grows a directory it did not ask for.
        if (! $manager->multiBrandEnabled()) {
            return '';
        }

        // current() falls back to the default brand — precisely what the
        // eloquent driver stamps on a create in the same situation.
        return $this->segmentFor($manager->current()->handle);
    }

    /** @return array<int, string>|null */
    protected function computeRead(): ?array
    {
        $manager = app('brand-context');

        if (! $manager->multiBrandEnabled()) {
            // The untouched pre-1.11 layout first, the default brand's
            // directory behind it, so an install that migrated and later turned
            // the flag back off still finds its data.
            return array_values(array_unique(['', $this->defaultHandle()]));
        }

        if (! $manager->hasCurrent()) {
            return $manager->failMode() === 'open' ? $this->all() : null;
        }

        $current = $this->segmentFor($manager->current()->handle);

        return $current === $this->defaultHandle()
            ? [$current, '']
            : [$current];
    }

    /**
     * Every segment that exists on disk, plus the pre-brand root.
     *
     * @return array<int, string>
     */
    public function all(): array
    {
        $manager = app('brand-context');

        if (! $manager->multiBrandEnabled()) {
            return [''];
        }

        $handles = \Goldnead\BrandContext\Models\Brand::query()
            ->orderBy('id')
            ->pluck('handle')
            ->map(fn (string $h) => $this->segmentFor($h))
            ->all();

        return array_values(array_unique([...$handles, '']));
    }

    protected function defaultHandle(): string
    {
        $manager = app('brand-context');

        // Single-brand must not touch the database for this: the flat driver is
        // the one people run without ever caring that a brands table exists.
        return $this->segmentFor($manager->multiBrandEnabled()
            ? $manager->default()->handle
            : (string) config('brand-context.default_handle', 'default'));
    }

    /** A brand handle becomes a directory name, so it has to be usable as one. */
    protected function segmentFor(string $handle): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '-', $handle) ?? '';

        return trim($safe, '-') ?: 'brand';
    }
}
