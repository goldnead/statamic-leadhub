<?php

namespace Goldnead\Leadhub\Console;

use Goldnead\Leadhub\Repositories\FlatFile\Index;
use Goldnead\Leadhub\Repositories\FlatFile\IndexBuilder;
use Illuminate\Console\Command;

class StacheWarmCommand extends Command
{
    protected $signature = 'leadhub:stache:warm
        {--clear : Delete the existing indexes before rebuilding}';

    protected $description = 'Rebuild the LeadHub flat-file driver indexes (contacts, tags, form_mappings).';

    public function handle(IndexBuilder $builder): int
    {
        if (config('leadhub.storage.driver') !== 'flat') {
            $this->warn('LeadHub is currently using the "eloquent" driver. Switch to "flat" first or this command will be a no-op.');

            return self::FAILURE;
        }

        $contacts = app('leadhub.index.contacts');
        $tags = app('leadhub.index.tags');
        $mappings = app('leadhub.index.form_mappings');

        if ($this->option('clear')) {
            $this->info('Clearing existing indexes...');
            foreach ([$contacts, $tags, $mappings] as $idx) {
                /** @var Index $idx */
                $idx->delete();
            }
        }

        $this->info('Rebuilding contacts index...');
        $builder->rebuildContacts($contacts);

        $this->info('Rebuilding tags index...');
        $builder->rebuildTags($tags);

        $this->info('Rebuilding form mappings index...');
        $builder->rebuildFormMappings($mappings);

        $this->info(sprintf(
            'Done. Contacts: %d, tags: %d, form mappings: %d.',
            count($contacts->items()),
            count($tags->items()),
            count($mappings->items()),
        ));

        return self::SUCCESS;
    }
}
