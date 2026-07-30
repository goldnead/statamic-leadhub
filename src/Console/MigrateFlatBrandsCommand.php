<?php

namespace Goldnead\Leadhub\Console;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Moves the pre-brand flat layout into a brand directory.
 *
 * Everything written before 1.11 sits directly under `content/leadhub/`:
 *
 *     content/leadhub/contacts/{uuid}.yaml
 *     content/leadhub/tags.yaml
 *
 * Under multi-brand those files are read as the **default brand's** and nobody
 * else's, so an install that flips the flag keeps working untouched. This
 * command makes that arrangement explicit by moving them into the brand's own
 * directory — which is what a second brand needs before it can exist without
 * the two sharing a root.
 *
 * It only ever **moves**. It never overwrites, never deletes, and a second run
 * is a no-op.
 */
class MigrateFlatBrandsCommand extends Command
{
    protected $signature = 'leadhub:migrate-flat-brands
        {--brand= : Target brand (handle or id). Defaults to the default brand.}
        {--dry-run : Show the moves without making them}';

    protected $description = 'Move the pre-brand flat-file layout into a brand directory.';

    /** Everything the flat driver owns, relative to the store root. */
    protected const ENTRIES = [
        'contacts',
        'events',
        'followups',
        'tags.yaml',
        'form-mappings.yaml',
        'segments.yaml',
    ];

    public function handle(): int
    {
        if (config('leadhub.storage.driver', 'eloquent') !== 'flat') {
            $this->warn('The flat driver is not active. Nothing to move.');
            $this->line('  This only rearranges files on disk; it does not migrate between drivers.');
            $this->line('  For that, use leadhub:storage:migrate.');

            return self::SUCCESS;
        }

        if (! BrandContext::multiBrandEnabled()) {
            $this->info('Single-brand install — the pre-brand layout is the correct layout.');
            $this->line('  Nothing moves until brand-context.multi_brand is on.');

            return self::SUCCESS;
        }

        $brand = $this->targetBrand();

        if (! $brand) {
            return self::FAILURE;
        }

        $root = rtrim((string) config('leadhub.storage.flat.path', base_path('content/leadhub')), '/');
        $segment = $this->segmentFor($brand->handle);
        $dryRun = (bool) $this->option('dry-run');

        $this->line("Target brand: {$brand->handle}  →  {$root}/{$segment}/");

        if ($dryRun) {
            $this->line('Dry run — nothing will be moved.');
        }

        $moved = 0;
        $skipped = 0;

        foreach (self::ENTRIES as $entry) {
            $source = $root.'/'.$entry;
            $target = $root.'/'.$segment.'/'.$entry;

            if (! File::exists($source)) {
                continue;
            }

            if (File::exists($target)) {
                // Never overwrite. A target that already exists means either a
                // finished migration or a genuine conflict, and neither is
                // something to resolve by clobbering.
                $this->warn("  skip  {$entry} — already present in {$segment}/");
                $skipped++;

                continue;
            }

            $this->line("  move  {$entry}  →  {$segment}/{$entry}");

            if (! $dryRun) {
                File::ensureDirectoryExists(dirname($target));
                File::move($source, $target);
            }

            $moved++;
        }

        if ($moved === 0 && $skipped === 0) {
            $this->info('Nothing to move — no pre-brand files found.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info(sprintf(
            '%s %d item(s)%s.',
            $dryRun ? 'Would move' : 'Moved',
            $moved,
            $skipped ? ", skipped {$skipped} already in place" : '',
        ));

        if (! $dryRun && $moved > 0) {
            $this->newLine();
            $this->warn('Rebuild the indexes so lookups see the new location:');
            $this->line('  php artisan leadhub:stache:warm --clear');
        }

        return self::SUCCESS;
    }

    protected function targetBrand(): ?Brand
    {
        $handle = $this->option('brand');

        if (! $handle) {
            return BrandContext::default();
        }

        $brand = Brand::query()
            ->where('handle', $handle)
            ->orWhere('id', $handle)
            ->first();

        if (! $brand) {
            $known = Brand::query()->orderBy('id')->pluck('handle')->implode(', ');
            $this->error("No brand [{$handle}]. Known: {$known}");

            return null;
        }

        return $brand;
    }

    /** Mirrors BrandSegments::segmentFor(), which decides the directory name. */
    protected function segmentFor(string $handle): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '-', $handle) ?? '';

        return trim($safe, '-') ?: 'brand';
    }
}
