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
 * LeadHub table removed — and hold every CP listing to an empty state plus a
 * line in the log.
 */

use Goldnead\Leadhub\Support\Setup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\CP\Nav;
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
    config()->set('leadhub.features.pipelines', true);
});

/**
 * Run $do against a database on which nothing has ever been migrated.
 *
 * All the tables at once, because that is the state being reproduced: an
 * install that never migrated has none, and guarding a page against one missing
 * table while the next query hits another is the same 500 one line further
 * down.
 *
 * An empty second connection, NOT `Schema::drop()` on the real one.
 *
 * The first version of this helper dropped the seventeen tables inside a
 * transaction and rolled back, on the theory that the savepoint would put the
 * schema back before testbench's own migration rollback ran. That holds on
 * SQLite and is false on MySQL: DDL commits implicitly there, so `drop table`
 * ended the transaction the moment it ran. The rollback afterwards then had no
 * transaction left to undo and died with
 * `SQLSTATE[42000] … 1305 SAVEPOINT trans2 does not exist` — in every one of
 * the thirteen guarded listings, three times over. The tables really were gone
 * by then, so nothing after it could pass either. Same trap that hit
 * statamic-webhook-manager today.
 *
 * Pointing the default connection at an empty in-memory SQLite instead needs no
 * DDL at all, so there is nothing for MySQL to commit behind our backs — and it
 * is the more faithful picture anyway: the addon is installed, its tables never
 * were. Taken from statamic-automations, which solved the same problem the same
 * way.
 */
function withoutLeadhubTables(Closure $do): void
{
    config()->set('database.connections.unmigrated', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);

    $previous = (string) config('database.default');

    config()->set('database.default', 'unmigrated');
    DB::setDefaultConnection('unmigrated');

    try {
        $do();
    } finally {
        config()->set('database.default', $previous);
        DB::setDefaultConnection($previous);
        DB::purge('unmigrated');
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
 * flagged — a guard on eight of them is a CRM that still answers 500 — the
 * tenth is the sync log, which caught its own missing table from the start and
 * then told nobody about it, and the eleventh is the follow-up list, which the
 * lint's pattern never saw.
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
    // Eleventh: the follow-up list. It stayed out of the first round because
    // the lint looks for the repository verbs the other listings use and this
    // one goes through FollowupService instead — the 500 was the same.
    'followups' => ['leadhub.followups.index', 'leadhub::Followups/Index', 'leadhub_followups'],
    // Twelfth and thirteenth: the Kanban board and the pipeline-management
    // screen, which had no guard at all. Both query their models directly
    // rather than through a repository, which is why the lint's pattern never
    // reached them.
    'pipeline board' => ['leadhub.pipelines.board', 'leadhub::Pipelines/Board', 'leadhub_pipelines'],
    'pipeline manage' => ['leadhub.pipelines.manage', 'leadhub::Pipelines/Manage', 'leadhub_pipelines'],
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

/**
 * The other half of that stand-down, which used to be a hole.
 *
 * Setup::guard() returns null on the flat driver for every table it is handed,
 * and for contacts, events, follow-ups, tags, segments, form mappings and
 * custom fields that is right: they have a YAML half and the screen works on
 * afterwards. Companies, tasks, pipelines and opportunities have no YAML half
 * at all, and the flat driver registers none of their migrations, so a flat
 * install with the flag switched on walked past the guard without logging a
 * word and died in the next query.
 *
 * A setup screen would have been the wrong answer too: `php artisan migrate`
 * finds nothing to run on that install. So these screens 404 — the module
 * really is not there — and the nav no longer offers them.
 */
dataset('eloquent-only modules', [
    'companies' => ['leadhub.features.companies', 'leadhub.companies.index'],
    'tasks' => ['leadhub.features.tasks', 'leadhub.tasks.index'],
    'pipeline board' => ['leadhub.features.pipelines', 'leadhub.pipelines.board'],
    'pipeline manage' => ['leadhub.features.pipelines', 'leadhub.pipelines.manage'],
]);

it('404s an eloquent-only module on the flat driver instead of dying in its first query', function (string $flag, string $route): void {
    config()->set($flag, true);
    config()->set('leadhub.storage.driver', 'flat');

    // 404, positively asserted. Before the fix this line read 500, and the log
    // was empty — which is the whole reason the case exists.
    $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route($route))
        ->assertStatus(404);
})->with('eloquent-only modules');

/**
 * Every label in the built CP nav, sections and children flattened.
 *
 * resolveChildren() first: LeadHub hangs its screens off one nav item as a
 * closure, and an unresolved closure is what `children()` hands back — mapping
 * over it reads as "no children" and would quietly make the assertions below
 * true whatever the driver says.
 */
function navLabels(): array
{
    return collect(Nav::build())
        ->flatMap(fn ($section) => collect($section['items'] ?? [])
            ->flatMap(fn ($item) => collect($item->resolveChildren()->children() ?: [])
                ->map->display()
                ->prepend($item->display())))
        ->all();
}

it('drops the eloquent-only nav items on the flat driver', function (): void {
    config()->set('leadhub.features.companies', true);
    config()->set('leadhub.features.tasks', true);
    config()->set('leadhub.features.pipelines', true);

    // Both directions, or this proves nothing: a navLabels() that silently
    // returned [] would satisfy every not->toContain() below and report a fix
    // that was never made.
    config()->set('leadhub.storage.driver', 'eloquent');
    expect(navLabels())->toContain('Companies')
        ->and(navLabels())->toContain('Tasks')
        ->and(navLabels())->toContain('Pipelines');

    // A nav item that leads to a 404 is still a defect, even once the
    // controller answers honestly.
    config()->set('leadhub.storage.driver', 'flat');
    expect(navLabels())->not->toContain('Companies')
        ->and(navLabels())->not->toContain('Tasks')
        ->and(navLabels())->not->toContain('Pipelines')
        // …and the screens that do have a flat half are still there.
        ->and(navLabels())->toContain('Contacts');
});
