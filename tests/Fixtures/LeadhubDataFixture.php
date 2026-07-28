<?php

namespace Goldnead\Leadhub\Tests\Fixtures;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A LeadHub install with real records in it.
 *
 * Written for the one thing the rest of the suite never gives a migration: a
 * table that already holds rows, put there by an older schema. Every insert
 * filters itself against the columns that actually exist on the connection, so
 * the same fixture seeds a 1.3.0 install and a 1.10.0 one — which is what lets
 * the migration path be walked one file at a time with data appearing between
 * the steps.
 *
 * Two properties are deliberate and load-bearing:
 *
 * - **Nothing here is a duplicate.** The brand-scoped uniques must be buildable
 *   over this data. Duplicates are opt-in, written by the test that wants them
 *   through MigrationPathTestCase::writeDuplicateContact().
 * - **Some identifiers are NULL.** Two contacts per batch have no email address
 *   and one event per batch has no dedupe_key. A unique does not constrain NULL
 *   on any engine, so those rows are not collisions — and anything that reports
 *   them as collisions would abort every real install, where contacts without
 *   an address and events without a dedupe key are the majority.
 */
class LeadhubDataFixture
{
    public function __construct(private Connection $connection) {}

    /**
     * The normalised address that `writeDuplicateContact()` will duplicate.
     */
    public static function duplicateProbe(int $batch = 0): string
    {
        return "ada.{$batch}@example.test";
    }

    /**
     * Write one batch of records. Every batch is self-contained and collides
     * with no other, so it can be called between migrations.
     *
     * @return int the number of rows written
     */
    public function seed(int $batch = 0): int
    {
        $written = 0;
        $now = now();
        $b = $batch;

        $contactIds = [];

        foreach ([
            ['ada', "ada.{$b}@example.test", 'Ada Lovelace'],
            ['grace', "grace.{$b}@example.test", 'Grace Hopper'],
            // Same person, mixed case in `email`, normalised to its own address.
            ['alan', "alan.{$b}@example.test", 'Alan Turing'],
            // No address at all. There will be a great many of these on a real
            // install, and none of them is a duplicate of any other.
            ['walkin', null, 'Walk-in, no address'],
            ['phone', null, 'Phone enquiry'],
        ] as $index => [$slug, $normalized, $name]) {
            $contactIds[$slug] = $this->insert('leadhub_contacts', [
                'uuid' => (string) Str::uuid(),
                'email' => $normalized === null ? null : Str::ucfirst($normalized),
                'email_normalized' => $normalized,
                'full_name' => $name,
                'first_name' => Str::before($name, ' '),
                'status' => $index % 2 === 0 ? 'new' : 'contacted',
                'source' => 'fixture',
                'consent' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $written++;
        }

        $tagIds = [];

        foreach (['vip' => 'VIP', 'cold' => 'Cold'] as $slug => $name) {
            $tagIds[$slug] = $this->insert('leadhub_tags', [
                'uuid' => (string) Str::uuid(),
                'name' => $name." {$b}",
                'slug' => "{$slug}-{$b}",
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $written++;
        }

        foreach ($tagIds as $tagId) {
            $this->insert('leadhub_contact_tag', [
                'contact_id' => $contactIds['ada'],
                'tag_id' => $tagId,
                'created_at' => $now,
                'updated_at' => $now,
            ], returnsId: false);

            $written++;
        }

        foreach ([
            ["event-{$b}-submitted", 'form.submitted'],
            ["event-{$b}-opened", 'email.opened'],
            // No dedupe_key: the ordinary case for anything written by hand.
            [null, 'note.added'],
        ] as [$dedupe, $type]) {
            $this->insert('leadhub_events', [
                'uuid' => (string) Str::uuid(),
                'contact_id' => $contactIds['ada'],
                'type' => $type,
                'summary' => "{$type} for batch {$b}",
                'dedupe_key' => $dedupe,
                'source_type' => 'fixture',
                'source_id' => (string) $b,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $written++;
        }

        $this->insert('leadhub_notes', [
            'uuid' => (string) Str::uuid(),
            'contact_id' => $contactIds['grace'],
            'body' => "A note from batch {$b}.",
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $written++;

        $this->insert('leadhub_followups', [
            'uuid' => (string) Str::uuid(),
            'contact_id' => $contactIds['grace'],
            'due_at' => $now->copy()->addDays(3),
            'note' => 'Call back',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $written++;

        $this->insert('leadhub_form_mappings', [
            'form_handle' => "contact-{$b}",
            'enabled' => true,
            'email_field' => 'email',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $written++;

        $this->insert('leadhub_sync_logs', [
            'contact_uuid' => (string) Str::uuid(),
            'destination' => 'crm',
            'driver' => 'webhook',
            'event' => 'contact.created',
            'status' => 'success',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $written++;

        $companyId = $this->insert('leadhub_companies', [
            'uuid' => (string) Str::uuid(),
            'name' => "Analytical Engines {$b}",
            'name_normalized' => "analytical engines {$b}",
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $written++;

        $this->insert('leadhub_contact_company', [
            'contact_id' => $contactIds['ada'],
            'company_id' => $companyId,
            'is_primary' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $written++;

        $pipelineId = $this->insert('leadhub_pipelines', [
            'uuid' => (string) Str::uuid(),
            'name' => "Sales {$b}",
            'slug' => "sales-{$b}",
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $written++;

        $stageIds = [];

        foreach (['new' => 'New', 'won' => 'Won'] as $slug => $name) {
            $stageIds[$slug] = $this->insert('leadhub_stages', [
                'uuid' => (string) Str::uuid(),
                'pipeline_id' => $pipelineId,
                'name' => $name,
                'slug' => $slug,
                'is_terminal' => $slug === 'won',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $written++;
        }

        $opportunityId = $this->insert('leadhub_opportunities', [
            'uuid' => (string) Str::uuid(),
            'contact_id' => $contactIds['ada'],
            'pipeline_id' => $pipelineId,
            'stage_id' => $stageIds['new'],
            'title' => "Deal {$b}",
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $written++;

        $this->insert('leadhub_stage_transitions', [
            'opportunity_id' => $opportunityId,
            'to_stage_id' => $stageIds['new'],
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $written++;

        $this->insert('leadhub_tasks', [
            'uuid' => (string) Str::uuid(),
            'contact_id' => $contactIds['grace'],
            'title' => "Prepare quote {$b}",
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $written++;

        $segmentId = $this->insert('leadhub_segments', [
            'uuid' => (string) Str::uuid(),
            'name' => "Hot leads {$b}",
            'handle' => "hot-{$b}",
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $written++;

        foreach ([$contactIds['ada'], $contactIds['grace']] as $contactId) {
            $this->insert('leadhub_segment_contact', [
                'segment_id' => $segmentId,
                'contact_id' => $contactId,
                'entered_at' => $now,
            ], returnsId: false);

            $written++;
        }

        return $written;
    }

    /**
     * Row counts for every LeadHub table that exists, so a test can prove that
     * an upgrade lost nothing.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];

        foreach (self::TABLES as $table) {
            if ($this->schema()->hasTable($table)) {
                $counts[$table] = $this->connection->table($table)->count();
            }
        }

        return $counts;
    }

    public const TABLES = [
        'leadhub_contacts',
        'leadhub_tags',
        'leadhub_contact_tag',
        'leadhub_events',
        'leadhub_notes',
        'leadhub_followups',
        'leadhub_form_mappings',
        'leadhub_sync_logs',
        'leadhub_companies',
        'leadhub_contact_company',
        'leadhub_tasks',
        'leadhub_pipelines',
        'leadhub_stages',
        'leadhub_opportunities',
        'leadhub_stage_transitions',
        'leadhub_segments',
        'leadhub_segment_contact',
    ];

    /**
     * Insert, keeping only the columns this schema version actually has.
     *
     * The fixture describes the records; the schema decides which fields of
     * them it can hold. That is what makes one fixture usable against every
     * released migration set.
     *
     * @param  array<string, mixed>  $row
     */
    private function insert(string $table, array $row, bool $returnsId = true): ?int
    {
        if (! $this->schema()->hasTable($table)) {
            return null;
        }

        $columns = $this->schema()->getColumnListing($table);

        $row = array_intersect_key($row, array_flip($columns));

        // Stamp the brand where the schema has grown one, exactly as the models
        // do. Without it the fixture could only ever write rows that need
        // backfilling, and would stop being insertable at all once brand_id
        // became NOT NULL — which is the state every install ends up in.
        if (in_array('brand_id', $columns, true) && ! array_key_exists('brand_id', $row)) {
            $row['brand_id'] = $this->defaultBrandId();
        }

        if ($returnsId) {
            return (int) $this->connection->table($table)->insertGetId($row);
        }

        $this->connection->table($table)->insert($row);

        return null;
    }

    private function defaultBrandId(): ?int
    {
        if (! $this->schema()->hasTable('brands')) {
            return null;
        }

        $id = $this->connection->table('brands')->where('is_default', true)->value('id')
            ?? $this->connection->table('brands')->min('id');

        return $id === null ? null : (int) $id;
    }

    private function schema(): \Illuminate\Database\Schema\Builder
    {
        return Schema::connection($this->connection->getName());
    }
}
