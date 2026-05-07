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
use Illuminate\Console\Command;

class StorageMigrateCommand extends Command
{
    protected $signature = 'leadhub:storage:migrate
        {--from= : Source driver: eloquent or flat (required)}
        {--to= : Target driver: eloquent or flat (required)}
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
