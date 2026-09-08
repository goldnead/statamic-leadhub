<?php

/**
 * A LeadHub whose migrations never ran must not answer 500.
 *
 * The addon installs from Composer in one step and creates its twenty-odd
 * tables in another, and nothing forces the second. Between the two, every
 * screen under `/cp/leadhub` is a query against a table that is not there:
 * `no such table: leadhub_contacts`, rendered as a stack trace where the CRM
 * should be. That is not a bug in the addon, it is an unfinished setup, and it
 * owes the reader one sentence instead.
 *
 * These tests reproduce that database — a working Statamic install with every
 * LeadHub table removed — and hold all nine listings to an empty state plus a
 * line in the log.
 */

use Goldnead\Leadhub\Support\Setup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Statamic\Facades\User;

beforeEach(function (): void {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('The flat driver ships no tables to miss; Setup::guard() stands down there.');
    }

    $this->user = User::make()->email('setup-guard@example.test')->makeSuper();
    $this->user->save();
    $this->actingAs($this->user);

    // The opt-in modules, so their listings are reachable at all — without the
    // flags they answer 404 and would pass every assertion below for the wrong
    // reason.
    config()->set('leadhub.features.companies', true);
    config()->set('leadhub.features.tasks', true);
    config()->set('leadhub.features.scoring', true);
});

/**
 * Run $do against a database with no LeadHub tables, then put them back.
 *
 * All of them at once, because that is the state being reproduced: an install
 * that never migrated has none: guarding a page against one missing table while
 * the next query hits another is the same 500 one line further down.
 *
 * The savepoint is not decoration. `loadMigrationsFrom()` in TestCase makes
 * testbench roll the migrations back when the test ends, and that rollback runs
 * before RefreshDatabase undoes anything — against tables this test dropped, it
 * dies on `no such index` and reports a broken harness instead of a result.
 * Rolling back to a savepoint restores the schema while the test is still
 * standing.
 */
function withoutLeadhubTables(Closure $do): void
{
    DB::beginTransaction();

    try {
        Schema::withoutForeignKeyConstraints(function (): void {
            foreach ([
                'leadhub_contact_tag',
                'leadhub_contact_company',
                'leadhub_segment_contact',
                'leadhub_events',
                'leadhub_followups',
                'leadhub_tasks',
                'leadhub_scoring_rules',
                'leadhub_custom_fields',
                'leadhub_form_mappings',
                'leadhub_segments',
                'leadhub_companies',
                'leadhub_tags',
                'leadhub_sync_logs',
                'leadhub_contacts',
            ] as $table) {
                Schema::dropIfExists($table);
            }
        });

        $do();
    } finally {
        DB::rollBack();
    }
}

/**
 * The Inertia payload of a CP page, as the browser would receive it.
 *
 * Requested with `X-Inertia` like every other CP test here, and not for
 * convenience: without the header Inertia renders its root view, and testbench
 * ships no `app` view — a plain GET would fail on the harness rather than on
 * anything this addon does.
 */
function inertiaPayload($test, string $route): array
{
    $response = $test->withHeaders(['X-Inertia' => 'true'])->get(cp_route($route));

    $response->assertStatus(200);

    return json_decode($response->getContent(), true) ?: [];
}

/**
 * Route name, the page it normally renders, and the table whose absence the
 * setup screen has to name first. Nine of these rows are the listings the lint
 * flagged — a guard on eight of them is a CRM that still answers 500 — and the
 * tenth is the sync log, which caught its own missing table from the start and
 * then told nobody about it.
 */
dataset('guarded listings', [
    'dashboard' => ['leadhub.dashboard', 'leadhub::Dashboard', 'leadhub_contacts'],
    'contacts' => ['leadhub.contacts.index', 'leadhub::Contacts/Index', 'leadhub_contacts'],
    'companies' => ['leadhub.companies.index', 'leadhub::Companies/Index', 'leadhub_companies'],
    'tasks' => ['leadhub.tasks.index', 'leadhub::Tasks/Index', 'leadhub_tasks'],
    'tags' => ['leadhub.tags.index', 'leadhub::Tags/Index', 'leadhub_tags'],
    'segments' => ['leadhub.segments.index', 'leadhub::Segments/Index', 'leadhub_segments'],
    'custom fields' => ['leadhub.custom-fields.index', 'leadhub::CustomFields/Index', 'leadhub_custom_fields'],
    'form mappings' => ['leadhub.forms.index', 'leadhub::Forms/Index', 'leadhub_form_mappings'],
    'scoring' => ['leadhub.scoring.index', 'leadhub::Scoring/Index', 'leadhub_scoring_rules'],
    'sync log' => ['leadhub.sync-log', 'leadhub::SyncLog', 'leadhub_sync_logs'],
]);

it('answers 200 instead of 500 when the tables are missing', function (string $route): void {
    withoutLeadhubTables(function () use ($route): void {
        $this->withHeaders(['X-Inertia' => 'true'])
            ->get(cp_route($route))
            ->assertStatus(200);
    });
})->with('guarded listings');

it('renders the setup screen and names the missing table', function (string $route, string $normal, string $table): void {
    withoutLeadhubTables(function () use ($route, $table): void {
        $payload = inertiaPayload($this, $route);

        expect($payload['component'])->toBe('leadhub::SetupRequired')
            ->and($payload['props']['tables'])->toContain($table)
            ->and($payload['props']['heading'])->not->toBeEmpty()
            ->and($payload['props']['description'])->not->toBeEmpty()
            // The title is the page's own, so the screen still reads as the
            // page that was asked for and not as one anonymous error for nine.
            ->and($payload['props']['title'])->not->toBeEmpty();

        // And no raw translation key, which is how this screen would fail
        // quietly: rendered, readable-looking, and saying `leadhub::…`.
        expect($payload['props']['heading'])->not->toContain('leadhub::');
        expect($payload['props']['description'])->not->toContain('leadhub::');
    });
})->with('guarded listings');

/**
 * The point of the guard is a readable page, not a quiet one.
 *
 * If this test ever goes red the addon has traded a visible 500 for a silent
 * nothing: an install that looks finished, renders an empty CRM forever, and
 * says so nowhere.
 */
it('writes the reason to the log', function (string $route): void {
    withoutLeadhubTables(function () use ($route): void {
        Log::spy();

        $this->withHeaders(['X-Inertia' => 'true'])
            ->get(cp_route($route))
            ->assertStatus(200);

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message) => str_contains($message, 'statamic-leadhub')
                && str_contains($message, 'php artisan migrate'))
            ->once();
    });
})->with('guarded listings');

it('still renders the normal listing on a migrated install', function (string $route, string $normal): void {
    expect(inertiaPayload($this, $route)['component'])->toBe($normal);
})->with('guarded listings');

it('stands down on the flat driver, whose tables are absent by design', function (): void {
    // README: "zero database required". A flat install has no leadhub_contacts
    // and is not meant to — telling its operator to run `php artisan migrate`
    // would replace nine working screens with nine wrong instructions.
    //
    // Asserted against the guard directly rather than through a route: the
    // repository bindings are chosen when the provider boots, so flipping the
    // driver mid-request would leave the eloquent repositories in place and
    // test something nobody runs.
    config()->set('leadhub.storage.driver', 'eloquent');
    expect(Setup::guard('Kontakte', 'leadhub_a_table_nobody_migrated'))->not->toBeNull();

    config()->set('leadhub.storage.driver', 'flat');
    expect(Setup::guard('Kontakte', 'leadhub_a_table_nobody_migrated'))->toBeNull();
});
