<?php

namespace Goldnead\Leadhub\Console;

use Goldnead\Leadhub\Repositories\Eloquent\EloquentContactRepository;
use Goldnead\Leadhub\Repositories\Eloquent\EloquentEventRepository;
use Goldnead\Leadhub\Repositories\Eloquent\EloquentFollowupRepository;
use Goldnead\Leadhub\Repositories\Eloquent\EloquentFormMappingRepository;
use Goldnead\Leadhub\Repositories\Eloquent\EloquentNoteRepository;
use Goldnead\Leadhub\Repositories\Eloquent\EloquentTagRepository;
use Goldnead\Leadhub\Repositories\FlatFile\FlatFileContactRepository;
use Goldnead\Leadhub\Repositories\FlatFile\FlatFileEventRepository;
use Goldnead\Leadhub\Repositories\FlatFile\FlatFileFollowupRepository;
use Goldnead\Leadhub\Repositories\FlatFile\FlatFileFormMappingRepository;
use Goldnead\Leadhub\Repositories\FlatFile\FlatFileNoteRepository;
use Goldnead\Leadhub\Repositories\FlatFile\FlatFileTagRepository;
use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Illuminate\Console\Command;

/**
 * Moves LeadHub data between the two storage drivers.
 *
 * ## Why this command asks which brand
 *
 * The eloquent driver is brand-scoped; the flat driver is not. `FileStore` is a
 * singleton bound to one path (`leadhub.storage.flat.path`) and nothing under
 * `Repositories/FlatFile` reads or writes a brand. So `content/leadhub/` holds
 * exactly one undifferentiated set of contacts.
 *
 * That has two consequences, and both are the reason this command refuses
 * rather than guesses:
 *
 * - **Migrating several brands to flat merges them.** The files carry no brand,
 *   so afterwards every brand reads every brand's contacts. Isolation is gone,
 *   silently, and there is nothing in the data to undo it with.
 * - **Migrating from flat has to pick a brand.** One flat store cannot be split
 *   across several, so somebody has to say which brand receives it.
 *
 * A console run also has no session, so without a brand the multi-brand scope
 * fails closed and the migration reads nothing at all — it used to report
 * "0 contact(s) processed" and exit successfully.
 *
 * Single-brand installs are unaffected: no option, no prompt, same behaviour as
 * before.
 */
class StorageMigrateCommand extends Command
{
    protected $signature = 'leadhub:storage:migrate
        {--from= : Source driver: eloquent or flat (required)}
        {--to= : Target driver: eloquent or flat (required)}
        {--brand= : Which brand to migrate (handle or id). Required on a multi-brand install.}
        {--dry-run : Show what would be migrated without writing}';

    protected $description = 'Move LeadHub data between the eloquent and flat-file storage drivers.';

    public function handle(): int
    {
        $from = $this->option('from');
        $to = $this->option('to');
        $dryRun = (bool) $this->option('dry-run');

        if (! in_array($from, ['eloquent', 'flat'], true) || ! in_array($to, ['eloquent', 'flat'], true)) {
            $this->error('--from and --to must each be one of: eloquent, flat');

            return self::FAILURE;
        }

        if ($from === $to) {
            $this->error('--from and --to cannot be the same.');

            return self::FAILURE;
        }

        $brand = $this->resolveBrand($to);

        if ($brand === false) {
            return self::FAILURE;
        }

        if ($brand === null) {
            return $this->migrate($from, $to, $dryRun);
        }

        $this->line("Brand: {$brand->handle}");

        return BrandContext::runFor($brand, fn () => $this->migrate($from, $to, $dryRun));
    }

    /**
     * The brand to run in, `null` for a single-brand install, or `false` when
     * the request cannot be honoured safely.
     */
    protected function resolveBrand(string $to): Brand|null|false
    {
        if (! BrandContext::multiBrandEnabled()) {
            return null;
        }

        $brands = Brand::query()->orderBy('id')->get();

        if ($brands->count() <= 1) {
            return $brands->first();
        }

        if (! $this->option('brand')) {
            $this->error('This install has more than one brand, so --brand is required.');

            if ($to === 'flat') {
                $this->line('  The flat driver has no per-brand layout. Everything under');
                $this->line('  content/leadhub/ is one undifferentiated set, so migrating a second');
                $this->line('  brand into it would merge the two, with nothing in the files to tell');
                $this->line('  them apart afterwards.');
                $this->newLine();
                $this->line('  Migrate one brand at a time, and point leadhub.storage.flat.path at a');
                $this->line('  directory of its own before each run.');
            } else {
                $this->line('  The flat store is one undifferentiated set of contacts and cannot be');
                $this->line('  split across brands — somebody has to say which brand receives it.');
            }

            $this->newLine();
            $this->line('  Brands on this install: '.$brands->pluck('handle')->implode(', '));

            return false;
        }

        $handle = $this->option('brand');
        $brand = $brands->first(fn (Brand $b) => $b->handle === $handle || (string) $b->id === (string) $handle);

        if (! $brand) {
            $this->error("No brand [{$handle}]. Known: ".$brands->pluck('handle')->implode(', '));

            return false;
        }

        return $brand;
    }

    protected function migrate(string $from, string $to, bool $dryRun): int
    {
        $this->warn(sprintf('Migrating LeadHub data from "%s" to "%s"%s', $from, $to, $dryRun ? ' (dry run)' : ''));

        $sources = $this->repositoriesFor($from);
        $targets = $this->repositoriesFor($to);

        // Tags
        $tags = $sources['tags']->all();
        $this->info(sprintf(' • %d tag(s) to migrate', $tags->count()));
        if (! $dryRun) {
            foreach ($tags as $tag) {
                $targets['tags']->create([
                    'uuid' => $tag->uuid,
                    'name' => $tag->name,
                    'slug' => $tag->slug,
                    'color' => $tag->color,
                ]);
            }
        }

        // Form mappings
        $mappings = $sources['form_mappings']->all();
        $this->info(sprintf(' • %d form mapping(s) to migrate', $mappings->count()));
        if (! $dryRun) {
            foreach ($mappings as $m) {
                $targets['form_mappings']->firstOrCreate($m->form_handle, $m->attributesToArray());
            }
        }

        // Contacts (with their notes, events, active followups)
        $page = 1;
        $totalContacts = 0;
        do {
            $paginator = $sources['contacts']->paginate(
                ['archived' => false],
                perPage: 100,
                page: $page,
            );

            foreach ($paginator->items() as $contact) {
                $totalContacts++;
                if ($dryRun) {
                    continue;
                }

                $newContact = $targets['contacts']->create($contact->attributesToArray());

                // Tags
                foreach ($contact->getRelation('tags') ?? collect() as $tag) {
                    if ($matched = $targets['tags']->findBySlug($tag->slug)) {
                        $targets['tags']->attach($newContact, $matched);
                    }
                }

                // Notes
                foreach ($sources['notes']->forContact($contact) as $note) {
                    $targets['notes']->create($newContact, $note->body, $note->user_id);
                }

                // Events
                foreach ($sources['events']->forContact($contact, perPage: 1000)->items() as $event) {
                    $targets['events']->record(
                        $newContact,
                        $event->type,
                        $event->summary,
                        (array) ($event->payload ?? []),
                        $event->actor_type,
                        $event->actor_id,
                    );
                }

                // Active followup
                if ($af = $sources['followups']->activeForOne($contact)) {
                    $targets['followups']->create(
                        $newContact,
                        $af->due_at,
                        $af->note,
                        $af->created_by,
                    );
                }
            }

            $page++;
        } while ($paginator->hasMorePages());

        $this->info(sprintf(' • %d contact(s) processed', $totalContacts));

        // Archived contacts
        $archivedPage = 1;
        $archivedTotal = 0;
        do {
            $archivedPaginator = $sources['contacts']->paginate(
                ['archived' => true],
                perPage: 100,
                page: $archivedPage,
            );

            foreach ($archivedPaginator->items() as $contact) {
                $archivedTotal++;
                if ($dryRun) {
                    continue;
                }
                $newContact = $targets['contacts']->create($contact->attributesToArray());
                $targets['contacts']->archive($newContact);
            }

            $archivedPage++;
        } while ($archivedPaginator->hasMorePages());

        if ($archivedTotal > 0) {
            $this->info(sprintf(' • %d archived contact(s) processed', $archivedTotal));
        }

        $this->info($dryRun ? 'Dry run complete — no data was written.' : 'Migration complete.');

        if (! $dryRun) {
            $this->warn(sprintf(
                "Don't forget to set LEADHUB_DRIVER=%s in your .env (or update config/leadhub.php) and clear caches.",
                $to,
            ));
        }

        return self::SUCCESS;
    }

    protected function repositoriesFor(string $driver): array
    {
        if ($driver === 'flat') {
            return [
                'contacts' => app(FlatFileContactRepository::class),
                'events' => app(FlatFileEventRepository::class),
                'notes' => app(FlatFileNoteRepository::class),
                'followups' => app(FlatFileFollowupRepository::class),
                'tags' => app(FlatFileTagRepository::class),
                'form_mappings' => app(FlatFileFormMappingRepository::class),
            ];
        }

        return [
            'contacts' => app(EloquentContactRepository::class),
            'events' => app(EloquentEventRepository::class),
            'notes' => app(EloquentNoteRepository::class),
            'followups' => app(EloquentFollowupRepository::class),
            'tags' => app(EloquentTagRepository::class),
            'form_mappings' => app(EloquentFormMappingRepository::class),
        ];
    }
}
