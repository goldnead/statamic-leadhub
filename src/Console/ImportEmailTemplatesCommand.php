<?php

namespace Goldnead\Leadhub\Console;

use Goldnead\Leadhub\Contracts\EmailTemplateSource;
use Goldnead\Leadhub\Services\EmailTemplates\EmailTemplateCollectionManager;
use Goldnead\Leadhub\Support\EmailTemplates\EmailTemplateData;
use Illuminate\Console\Command;

/**
 * Import existing file-based email templates (currently: statamic-marketing)
 * into the native `email_templates` collection. The slug is preserved 1:1 so
 * existing automations/campaigns that reference a template by slug keep
 * working after the import.
 */
class ImportEmailTemplatesCommand extends Command
{
    protected $signature = 'leadhub:email-templates:import
        {--dry-run : List what would be imported without writing any entries}
        {--overwrite : Overwrite entries whose slug already exists}
        {--source= : Only import from a single source (matched against its label)}';

    protected $description = 'Import file-based email templates from sibling addons into the email_templates collection.';

    public function handle(EmailTemplateCollectionManager $collection): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $overwrite = (bool) $this->option('overwrite');
        $only = $this->option('source');

        if (! $dryRun) {
            $collection->ensure();
        }

        /** @var iterable<EmailTemplateSource> $sources */
        $sources = app()->tagged('leadhub.email_template_sources');

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $total = 0;

        foreach ($sources as $source) {
            if (! $source instanceof EmailTemplateSource) {
                continue;
            }

            if ($only && $source->label() !== $only) {
                continue;
            }

            $templates = $source->all();

            if ($templates === []) {
                continue;
            }

            $this->line("Source <info>{$source->label()}</info>: ".count($templates).' template(s)');

            foreach ($templates as $template) {
                if (! $template instanceof EmailTemplateData) {
                    continue;
                }

                $total++;
                $exists = (bool) $collection->findBySlug($template->slug);

                if ($exists && ! $overwrite) {
                    $skipped++;
                    $this->line("  - <comment>skip</comment> {$template->slug} (exists)");

                    continue;
                }

                if ($dryRun) {
                    $verb = $exists ? 'update' : 'create';
                    $this->line("  - <comment>{$verb}</comment> {$template->slug} (dry-run)");

                    continue;
                }

                [, $wasCreated] = $collection->upsert($template);

                if ($wasCreated) {
                    $created++;
                    $this->line("  - <info>created</info> {$template->slug}");
                } else {
                    $updated++;
                    $this->line("  - <info>updated</info> {$template->slug}");
                }
            }
        }

        if ($total === 0) {
            $this->warn('No source templates found. Nothing to import.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info("Dry-run complete. {$total} template(s) discovered.");

            return self::SUCCESS;
        }

        $this->info("Done. Created: {$created}, updated: {$updated}, skipped: {$skipped}.");

        return self::SUCCESS;
    }
}
