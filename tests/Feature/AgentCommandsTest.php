<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Facades\LeadHub;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;

/**
 * `leadhub:kontakt` and `leadhub:heute`: the read-only bridge for agents.
 *
 * An agent outside the app asks "what do we know about Anna?" and "what is due
 * today?" over SSH, and the app answers itself — no rebuilt SQL, no second
 * truth. Three properties matter more than the rest:
 *
 *  - the JSON has a fixed shape, because a script parses it;
 *  - a brand never sees another brand's people, and without --brand a
 *    multi-brand install answers for all of them, grouped;
 *  - the commands write nothing, dispatch nothing and log nothing. A lookup
 *    that touched `last_activity_at` or fired an event would turn every agent
 *    question into an activity on the contact.
 */
const BRIDGE_CONTACT_KEYS = [
    'id', 'uuid', 'email', 'first_name', 'last_name', 'full_name', 'phone', 'company', 'status', 'source',
    'tags', 'owner_id', 'created_at', 'last_activity_at', 'revenue_cent', 'revenue_refunded_cent',
    'net_revenue_cent', 'revenue_currency', 'purchase_count', 'first_purchase_at', 'last_purchase_at',
    'brand', 'archived_at', 'revenue', 'timeline', 'followups', 'tasks', 'opportunities',
];

const BRIDGE_MATCH_KEYS = ['id', 'uuid', 'name', 'email', 'tags', 'last_activity_at', 'archived', 'brand'];

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-30 10:00:00'));

    config()->set('leadhub.features.tasks', true);
    config()->set('leadhub.features.pipelines', true);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** Run a bridge command with --json and decode what it printed. */
function bridgeJson(string $command, array $arguments = []): array
{
    $exit = Artisan::call($command, $arguments + ['--json' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0, $output);

    return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
}

function eloquentDriver(): bool
{
    return config('leadhub.storage.driver', 'eloquent') === 'eloquent';
}

/** Anna with a tag, a follow-up due today, a task, a deal and a purchase. */
function anna(): array
{
    $anna = LeadHub::create([
        'email' => 'anna.alt@example.com',
        'first_name' => 'Anna',
        'last_name' => 'Altstimme',
        'tags' => ['kundin'],
    ]);

    LeadHub::createFollowUp($anna['id'], ['due_at' => now()->addHours(3)->toDateTimeString(), 'note' => 'Nach dem Workshop fragen']);

    if (eloquentDriver()) {
        LeadHub::createTask(['title' => 'Angebot schicken', 'due_at' => now()->addHours(2)->toDateTimeString()], $anna['id']);
        LeadHub::createPipeline('Coaching', [
            ['name' => 'Anfrage'],
            ['name' => 'Gewonnen', 'is_terminal' => true, 'terminal_outcome' => 'won'],
        ]);
        LeadHub::upsertOpportunity($anna['id'], 'coaching', ['title' => '10er-Karte', 'value_estimate' => 900]);
        LeadHub::recordRevenue('anna.alt@example.com', 'test:1', 12000, 'EUR', now()->subDay(), 'test');
    }

    return $anna;
}

// -- leadhub:kontakt --------------------------------------------------------

it('returns the full profile for an exact email hit', function (): void {
    anna();

    $json = bridgeJson('leadhub:kontakt', ['suche' => 'ANNA.alt@example.com']);

    expect($json['status'])->toBe('found')
        ->and($json['total'])->toBe(1)
        ->and($json['contact']['email'])->toBe('anna.alt@example.com')
        ->and($json['contact']['full_name'])->toBe('Anna Altstimme')
        ->and($json['contact']['tags'])->toBe(['kundin'])
        ->and($json['contact']['followups'])->toHaveCount(1)
        ->and($json['contact']['followups'][0]['note'])->toBe('Nach dem Workshop fragen')
        ->and($json['contact']['timeline']['entries'])->not->toBeEmpty();

    if (eloquentDriver()) {
        expect($json['contact']['tasks'])->toHaveCount(1)
            ->and($json['contact']['tasks'][0]['title'])->toBe('Angebot schicken')
            ->and($json['contact']['opportunities'])->toHaveCount(1)
            ->and($json['contact']['opportunities'][0]['stage_name'])->toBe('Anfrage')
            ->and($json['contact']['revenue'])->toHaveCount(1)
            ->and($json['contact']['revenue'][0]['amount_cent'])->toBe(12000)
            ->and($json['contact']['net_revenue_cent'])->toBe(12000);
    }
});

it('finds a contact by id and by uuid', function (): void {
    $anna = anna();

    expect(bridgeJson('leadhub:kontakt', ['suche' => (string) $anna['id']])['contact']['email'])->toBe('anna.alt@example.com')
        ->and(bridgeJson('leadhub:kontakt', ['suche' => (string) $anna['uuid']])['contact']['email'])->toBe('anna.alt@example.com');
});

it('finds a single contact by part of the name', function (): void {
    anna();
    LeadHub::create(['email' => 'bert@example.com', 'first_name' => 'Bert', 'last_name' => 'Bass']);

    $json = bridgeJson('leadhub:kontakt', ['suche' => 'altst']);

    expect($json['status'])->toBe('found')
        ->and($json['contact']['email'])->toBe('anna.alt@example.com');
});

it('lists the candidates when the search is ambiguous, and no profile', function (): void {
    anna();
    LeadHub::create(['email' => 'hannah@example.com', 'first_name' => 'Hannah', 'last_name' => 'Hoch']);
    LeadHub::create(['email' => 'bert@example.com', 'first_name' => 'Bert', 'last_name' => 'Bass']);

    $json = bridgeJson('leadhub:kontakt', ['suche' => 'ann']);

    expect($json['status'])->toBe('ambiguous')
        ->and($json['total'])->toBe(2)
        ->and($json['contact'])->toBeNull()
        ->and(collect($json['matches'])->pluck('email')->sort()->values()->all())
        ->toBe(['anna.alt@example.com', 'hannah@example.com'])
        ->and(array_keys($json['matches'][0]))->toBe(BRIDGE_MATCH_KEYS);
});

it('caps the candidate list at ten', function (): void {
    foreach (range(1, 12) as $i) {
        LeadHub::create(['email' => "chor{$i}@example.com", 'first_name' => 'Chor', 'last_name' => "Mitglied {$i}"]);
    }

    $json = bridgeJson('leadhub:kontakt', ['suche' => 'chor']);

    expect($json['status'])->toBe('ambiguous')
        ->and($json['matches'])->toHaveCount(10)
        ->and($json['total'])->toBe(12);
});

it('says so when nobody matches, and still exits 0', function (): void {
    anna();

    $json = bridgeJson('leadhub:kontakt', ['suche' => 'niemand@example.com']);

    expect($json)->toBe([
        'query' => 'niemand@example.com',
        'status' => 'none',
        'brand' => null,
        'total' => 0,
        'matches' => [],
        'contact' => null,
    ]);
});

it('keeps the profile shape stable', function (): void {
    anna();

    $json = bridgeJson('leadhub:kontakt', ['suche' => 'anna.alt@example.com', '--timeline' => 1]);

    expect(array_keys($json))->toBe(['query', 'status', 'brand', 'total', 'matches', 'contact'])
        ->and(array_keys($json['contact']))->toBe(BRIDGE_CONTACT_KEYS)
        ->and(array_keys($json['contact']['timeline']))->toBe(['entries', 'total', 'sources', 'failed', 'stats'])
        ->and($json['contact']['timeline']['entries'])->toHaveCount(1)
        ->and(array_keys($json['contact']['timeline']['entries'][0]))
        ->toBe(['id', 'source', 'kind', 'at', 'summary', 'badge', 'amount', 'detail', 'actor'])
        ->and(array_keys($json['contact']['followups'][0]))->toBe(['id', 'uuid', 'due_at', 'note', 'is_overdue'])
        ->and(array_keys($json['matches'][0]))->toBe(BRIDGE_MATCH_KEYS);

    if (eloquentDriver()) {
        expect(array_keys($json['contact']['tasks'][0]))->toBe([
            'id', 'uuid', 'contact_id', 'opportunity_id', 'title', 'status', 'priority', 'due_at',
            'assignee_id', 'completed_at', 'is_overdue', 'is_completed',
        ])->and(array_keys($json['contact']['opportunities'][0]))->toBe([
            'id', 'uuid', 'contact_id', 'pipeline_id', 'stage_id', 'title', 'value_estimate', 'confidence',
            'status', 'outcome', 'owner_id', 'stage_name', 'stage_slug', 'pipeline_name', 'closed_at', 'last_activity_at',
        ]);
    }
});

it('shows only open tasks and open deals in the profile', function (): void {
    if (! eloquentDriver()) {
        test()->markTestSkipped('Tasks and pipelines target the eloquent driver.');
    }

    $anna = anna();
    $done = LeadHub::createTask(['title' => 'Erledigt'], $anna['id']);
    LeadHub::completeTask($done['id']);

    $json = bridgeJson('leadhub:kontakt', ['suche' => 'anna.alt@example.com']);

    expect(collect($json['contact']['tasks'])->pluck('title')->all())->toBe(['Angebot schicken']);
});

it('prints a readable profile without --json', function (): void {
    anna();

    Artisan::call('leadhub:kontakt', ['suche' => 'anna.alt@example.com']);
    $output = Artisan::output();

    expect($output)->toContain('Anna Altstimme')
        ->toContain('anna.alt@example.com')
        ->toContain('kundin')
        ->toContain('Nach dem Workshop fragen');
});

// -- leadhub:heute ----------------------------------------------------------

it('counts new contacts and what is due today', function (): void {
    anna();

    Carbon::setTestNow(now()->subDays(3));
    LeadHub::create(['email' => 'vor-drei-tagen@example.com', 'first_name' => 'Drei']);
    Carbon::setTestNow(now()->subDays(20));
    LeadHub::create(['email' => 'alt@example.com', 'first_name' => 'Alt']);
    Carbon::setTestNow(Carbon::parse('2026-09-30 10:00:00'));

    $json = bridgeJson('leadhub:heute');

    expect(array_keys($json))->toBe(['generated_at', 'multi_brand', 'brand', 'brands'])
        ->and($json['multi_brand'])->toBeFalse()
        ->and($json['brands'])->toHaveCount(1);

    $today = $json['brands'][0];

    expect(array_keys($today))->toBe(['brand', 'new_contacts', 'followups', 'tasks'])
        ->and($today['brand'])->toBeNull()
        ->and($today['new_contacts']['last_24h']['count'])->toBe(1)
        ->and($today['new_contacts']['last_7d']['count'])->toBe(2)
        ->and(collect($today['new_contacts']['last_7d']['items'])->pluck('email')->all())
        ->toBe(['anna.alt@example.com', 'vor-drei-tagen@example.com'])
        ->and(array_keys($today['new_contacts']['last_24h']['items'][0]))->toBe(['id', 'uuid', 'name', 'email', 'status', 'created_at'])
        ->and($today['followups']['due_today']['count'])->toBe(1)
        ->and($today['followups']['overdue']['count'])->toBe(0)
        ->and(array_keys($today['followups']['due_today']['items'][0]))->toBe(['id', 'due_at', 'note', 'contact'])
        ->and($today['followups']['due_today']['items'][0]['contact']['email'])->toBe('anna.alt@example.com');

    if (eloquentDriver()) {
        expect($today['tasks']['available'])->toBeTrue()
            ->and($today['tasks']['due_today']['count'])->toBe(1)
            ->and(array_keys($today['tasks']['due_today']['items'][0]))->toBe(['id', 'title', 'priority', 'due_at', 'contact'])
            ->and($today['tasks']['due_today']['items'][0]['contact']['email'])->toBe('anna.alt@example.com');
    } else {
        expect($today['tasks'])->toBe([
            'available' => false,
            'due_today' => ['count' => 0, 'items' => []],
            'overdue' => ['count' => 0, 'items' => []],
        ]);
    }
});

it('lists at most five per list but counts all', function (): void {
    foreach (range(1, 7) as $i) {
        LeadHub::create(['email' => "neu{$i}@example.com"]);
    }

    $today = bridgeJson('leadhub:heute')['brands'][0];

    expect($today['new_contacts']['last_24h']['count'])->toBe(7)
        ->and($today['new_contacts']['last_24h']['items'])->toHaveCount(5);
});

it('prints a readable summary without --json', function (): void {
    anna();

    Artisan::call('leadhub:heute');

    expect(Artisan::output())->toContain('anna.alt@example.com');
});

// -- Brands -----------------------------------------------------------------

/** Two brands, each with an Anna and one more contact. */
function twoBrandsWithAnna(): array
{
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    $a = Brand::create(['handle' => 'chor-a', 'name' => 'Chor A']);
    $b = Brand::create(['handle' => 'chor-b', 'name' => 'Chor B']);

    BrandContext::runFor($a, function (): void {
        LeadHub::create(['email' => 'anna@chor-a.example', 'first_name' => 'Anna', 'last_name' => 'Aus A']);
        LeadHub::create(['email' => 'bert@chor-a.example', 'first_name' => 'Bert']);
    });
    BrandContext::runFor($b, function (): void {
        $anna = LeadHub::create(['email' => 'anna@chor-b.example', 'first_name' => 'Anna', 'last_name' => 'Aus B']);
        LeadHub::createFollowUp($anna['id'], ['due_at' => now()->addHour()->toDateTimeString()]);
    });

    app('brand-context')->forget();

    return [$a, $b];
}

it('searches every brand without --brand and names the brand of each match', function (): void {
    twoBrandsWithAnna();

    $json = bridgeJson('leadhub:kontakt', ['suche' => 'anna']);

    expect($json['status'])->toBe('ambiguous')
        ->and(collect($json['matches'])->mapWithKeys(fn ($m) => [$m['email'] => $m['brand']])->sortKeys()->all())
        ->toBe(['anna@chor-a.example' => 'chor-a', 'anna@chor-b.example' => 'chor-b']);
});

it('answers only from the brand given with --brand', function (): void {
    twoBrandsWithAnna();

    $json = bridgeJson('leadhub:kontakt', ['suche' => 'anna', '--brand' => 'chor-b']);

    expect($json['status'])->toBe('found')
        ->and($json['brand'])->toBe('chor-b')
        ->and($json['contact']['email'])->toBe('anna@chor-b.example')
        ->and($json['contact']['brand'])->toBe('chor-b')
        ->and($json['contact']['followups'])->toHaveCount(1);

    // An exact address from another brand is not visible from this one.
    expect(bridgeJson('leadhub:kontakt', ['suche' => 'anna@chor-a.example', '--brand' => 'chor-b'])['status'])->toBe('none');
});

it('builds the profile inside the brand that owns the contact', function (): void {
    twoBrandsWithAnna();

    $json = bridgeJson('leadhub:kontakt', ['suche' => 'anna@chor-b.example']);

    expect($json['status'])->toBe('found')
        ->and($json['contact']['brand'])->toBe('chor-b')
        ->and($json['contact']['followups'])->toHaveCount(1);
});

it('groups today by brand, and filters with --brand', function (): void {
    twoBrandsWithAnna();

    $json = bridgeJson('leadhub:heute');

    expect($json['multi_brand'])->toBeTrue()
        ->and(collect($json['brands'])->pluck('brand')->all())->toBe(['default', 'chor-a', 'chor-b']);

    $byBrand = collect($json['brands'])->keyBy('brand');

    expect($byBrand['chor-a']['new_contacts']['last_24h']['count'])->toBe(2)
        ->and($byBrand['chor-a']['followups']['due_today']['count'])->toBe(0)
        ->and($byBrand['chor-b']['new_contacts']['last_24h']['count'])->toBe(1)
        ->and($byBrand['chor-b']['followups']['due_today']['count'])->toBe(1);

    $only = bridgeJson('leadhub:heute', ['--brand' => 'chor-a']);

    expect($only['brand'])->toBe('chor-a')
        ->and(collect($only['brands'])->pluck('brand')->all())->toBe(['chor-a']);
});

it('refuses an unknown brand instead of answering for none', function (): void {
    twoBrandsWithAnna();

    $exit = Artisan::call('leadhub:kontakt', ['suche' => 'anna', '--brand' => 'gibt-es-nicht', '--json' => true]);
    $json = json_decode(Artisan::output(), true);

    expect($exit)->toBe(1)
        ->and($json)->toBe(['error' => 'Unknown brand [gibt-es-nicht].']);

    expect(Artisan::call('leadhub:heute', ['--brand' => 'gibt-es-nicht', '--json' => true]))->toBe(1);
});

// -- Read only --------------------------------------------------------------

it('writes nothing, dispatches nothing and logs nothing', function (): void {
    anna();
    LeadHub::create(['email' => 'hannah@example.com', 'first_name' => 'Hannah']);

    $contentRoot = rtrim((string) config('leadhub.storage.flat.path'), '/');
    $snapshot = fn () => is_dir($contentRoot)
        ? collect(File::allFiles($contentRoot))->mapWithKeys(fn ($f) => [$f->getPathname() => md5_file($f->getPathname())])->sortKeys()->all()
        : [];
    $before = $snapshot();
    $contactBefore = app(ContactRepository::class)->findByEmailNormalized('anna.alt@example.com')?->getAttributes();

    $writes = [];
    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|replace|upsert|create|alter|drop|truncate)\b/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });

    $events = [];
    Event::listen('*', function (string $name) use (&$events): void {
        if (str_starts_with($name, 'Goldnead\\')
            || preg_match('/^eloquent\.(creat|updat|sav|delet|restor|forceDelet)/', $name)
            || $name === MessageLogged::class) {
            $events[] = $name;
        }
    });

    foreach ([
        ['leadhub:kontakt', ['suche' => 'anna.alt@example.com']],
        ['leadhub:kontakt', ['suche' => 'ann']],
        ['leadhub:kontakt', ['suche' => 'niemand']],
        ['leadhub:heute', []],
    ] as [$command, $arguments]) {
        Artisan::call($command, $arguments);
        Artisan::call($command, $arguments + ['--json' => true]);
    }

    expect($writes)->toBe([])
        ->and($events)->toBe([])
        ->and($snapshot())->toBe($before)
        ->and(app(ContactRepository::class)->findByEmailNormalized('anna.alt@example.com')?->getAttributes())->toEqual($contactBefore);
});

// -- The getters the contact screen and the command share ------------------

it('gives the contact screen and the command the same tasks and deals', function (): void {
    if (! eloquentDriver()) {
        test()->markTestSkipped('Tasks and pipelines target the eloquent driver.');
    }

    $anna = anna();
    $done = LeadHub::createTask(['title' => 'Erledigt'], $anna['id']);
    LeadHub::completeTask($done['id']);

    expect(collect(LeadHub::tasksFor($anna['id']))->pluck('title')->all())->toBe(['Angebot schicken', 'Erledigt'])
        ->and(collect(LeadHub::tasksFor($anna['id'], openOnly: true))->pluck('title')->all())->toBe(['Angebot schicken'])
        ->and(collect(LeadHub::opportunitiesFor($anna['id']))->pluck('stage_name')->all())->toBe(['Anfrage'])
        ->and(LeadHub::followupsFor($anna['id']))->toHaveCount(1)
        ->and(LeadHub::timelineFor($anna['id'], 2)['entries'])->toHaveCount(2)
        ->and(LeadHub::timelineFor('does-not-exist'))->toBeNull()
        ->and(LeadHub::tasksFor('does-not-exist'))->toBe([]);

    config()->set('leadhub.features.tasks', false);
    expect(LeadHub::tasksFor($anna['id']))->toBe([]);
});
