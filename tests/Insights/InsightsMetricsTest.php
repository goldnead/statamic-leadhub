<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Leadhub\Integrations\Insights\ContactsActive;
use Goldnead\Leadhub\Integrations\Insights\ContactsCreated;
use Goldnead\Leadhub\Integrations\Insights\LeadhubMetric;
use Goldnead\Leadhub\Integrations\Insights\OpportunitiesWon;
use Goldnead\Leadhub\Integrations\Insights\OpportunityValueWon;
use Goldnead\Leadhub\Integrations\Insights\ScoreChanges;
use Goldnead\Leadhub\Integrations\Insights\TasksCompleted;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Event;
use Goldnead\Leadhub\Models\Opportunity;
use Goldnead\Leadhub\Models\Pipeline;
use Goldnead\Leadhub\Models\Stage;
use Goldnead\Leadhub\Models\Task;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Period;
use Goldnead\StatamicInsights\Support\Unit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The six numbers this addon offers the analytics addon.
 *
 * Every expectation below is worked out by hand from one small fixture, and the
 * fixture holds every awkward case on purpose: an arrival before the window, an
 * archived contact, a merged duplicate, a lost deal beside two won ones, a
 * cancelled task, a timeline entry of the wrong type. A figure that drifts
 * shows up here as an arithmetic disagreement rather than as a green suite over
 * a different report.
 *
 * The dashboard and the pipeline board are untouched by all of it. They answer
 * "how things stand"; these answer "what happened between two dates", and the
 * two are deliberately not the same query.
 *
 * Time is frozen. The buckets are asserted as literal dates, and a suite that
 * ran across midnight would otherwise fail once a night for reasons that have
 * nothing to do with the code.
 */

/** The day everything below is measured from. */
const HEUTE = '2026-08-20 12:00:00';

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse(HEUTE));

    // Named outright rather than inherited from the environment. The CI matrix
    // runs this suite twice, once per storage driver, and these figures read
    // tables — under the flat driver they are unavailable by design, which is
    // asserted in its own test below rather than by half the file falling
    // silent.
    config()->set('leadhub.storage.driver', 'eloquent');

    // The three opt-in modules these figures depend on. Off by default, which
    // is also asserted below.
    config()->set('leadhub.features.tasks', true);
    config()->set('leadhub.features.pipelines', true);
    config()->set('leadhub.features.scoring', true);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

// -- The fixture -------------------------------------------------------------

/**
 * Six contacts, five deals, four tasks, five timeline entries.
 *
 * Small enough to add up in the head. One contact arrived before the window,
 * one was archived inside it, and one was merged away — the three cases that
 * separate "arrived" from "is on the list".
 */
function leadhubInsightsFixture(): array
{
    $vorher = leadhubContact('alt@example.com', '2026-07-01 09:00:00', ['status' => 'contacted', 'source' => 'form']);

    $anna = leadhubContact('anna@example.com', '2026-08-12 10:00:00', [
        'status' => 'qualified',
        'source' => 'form',
        'utm_source' => 'newsletter',
        'utm_campaign' => 'sommer-2026',
    ]);

    // No attribution at all. A row in every split, never an omission.
    $bruno = leadhubContact('bruno@example.com', '2026-08-14 08:00:00', [
        'status' => 'new',
        'source' => 'form',
    ]);

    $clara = leadhubContact('clara@example.com', '2026-08-14 19:00:00', [
        'status' => 'won',
        'source' => 'form',
        'utm_source' => 'newsletter',
        'utm_campaign' => 'sommer-2026',
    ]);

    // Arrived on the 16th, archived on the 18th: in the stock for two days and
    // out of it afterwards.
    $dora = leadhubContact('dora@example.com', '2026-08-16 11:00:00', [
        'status' => 'archived',
        'archived_at' => '2026-08-18 09:00:00',
    ]);

    // A duplicate of Anna, merged into her. It arrived — so it counts as an
    // arrival — but it was never a second person and is in no stock.
    $doppelt = leadhubContact('anna+2@example.com', '2026-08-17 07:00:00', [
        'status' => 'new',
        'source' => 'import',
        'merged_into_contact_id' => $anna->id,
    ]);

    $pipeline = Pipeline::create(['name' => 'Sales', 'slug' => 'sales', 'sort_order' => 1, 'is_active' => true]);

    $offen = Stage::create(['pipeline_id' => $pipeline->id, 'name' => 'Open', 'slug' => 'open', 'sort_order' => 1]);
    $gewonnen = Stage::create([
        'pipeline_id' => $pipeline->id, 'name' => 'Won', 'slug' => 'won', 'sort_order' => 2,
        'is_terminal' => true, 'terminal_outcome' => Stage::OUTCOME_WON,
    ]);

    leadhubOpportunity($anna, $pipeline, $gewonnen, [
        'title' => 'Website', 'value_estimate' => '1500.00',
        'status' => Opportunity::STATUS_CLOSED, 'outcome' => Stage::OUTCOME_WON,
        'won_at' => '2026-08-13 12:00:00', 'closed_at' => '2026-08-13 12:00:00',
    ]);

    leadhubOpportunity($clara, $pipeline, $gewonnen, [
        'title' => 'Kleinauftrag', 'value_estimate' => '250.50',
        'status' => Opportunity::STATUS_CLOSED, 'outcome' => Stage::OUTCOME_WON,
        'won_at' => '2026-08-17 15:00:00', 'closed_at' => '2026-08-17 15:00:00',
    ]);

    // Lost, and expensive. A win figure that read `closed_at` instead of
    // `won_at` would swallow it whole.
    leadhubOpportunity($bruno, $pipeline, $gewonnen, [
        'title' => 'Verloren', 'value_estimate' => '9999.00',
        'status' => Opportunity::STATUS_CLOSED, 'outcome' => Stage::OUTCOME_LOST,
        'lost_at' => '2026-08-15 10:00:00', 'closed_at' => '2026-08-15 10:00:00',
    ]);

    // Still running. An intention, not a win.
    leadhubOpportunity($bruno, $pipeline, $offen, [
        'title' => 'Laeuft', 'value_estimate' => '4000.00',
        'status' => Opportunity::STATUS_OPEN,
    ]);

    // Won before the window opened.
    leadhubOpportunity($vorher, $pipeline, $gewonnen, [
        'title' => 'Alt', 'value_estimate' => '1000.00',
        'status' => Opportunity::STATUS_CLOSED, 'outcome' => Stage::OUTCOME_WON,
        'won_at' => '2026-07-05 09:00:00', 'closed_at' => '2026-07-05 09:00:00',
    ]);

    leadhubTask($anna, ['status' => Task::STATUS_DONE, 'completed_at' => '2026-08-12 16:00:00']);
    // No contact. Internal work is work.
    leadhubTask(null, ['status' => Task::STATUS_DONE, 'completed_at' => '2026-08-19 16:00:00']);
    // Ticked off once and cancelled since. The stamp survived the status.
    leadhubTask($bruno, ['status' => Task::STATUS_CANCELLED, 'completed_at' => '2026-08-14 16:00:00']);
    leadhubTask($bruno, ['status' => Task::STATUS_OPEN, 'due_at' => '2026-08-25 09:00:00']);

    leadhubEvent($anna, Event::TYPE_SCORE_CHANGED, '2026-08-12 10:05:00');
    leadhubEvent($bruno, Event::TYPE_SCORE_CHANGED, '2026-08-12 11:00:00');
    leadhubEvent($clara, Event::TYPE_SCORE_CHANGED, '2026-08-19 09:00:00');
    leadhubEvent($anna, Event::TYPE_NOTE_ADDED, '2026-08-13 09:00:00');
    leadhubEvent($vorher, Event::TYPE_SCORE_CHANGED, '2026-07-01 09:30:00');

    return compact('vorher', 'anna', 'bruno', 'clara', 'dora', 'doppelt');
}

function leadhubContact(string $email, string $createdAt, array $overrides = []): Contact
{
    return Contact::create(array_merge([
        'email' => $email,
        'full_name' => explode('@', $email)[0],
        'status' => 'new',
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ], $overrides));
}

function leadhubOpportunity(Contact $contact, Pipeline $pipeline, Stage $stage, array $overrides = []): Opportunity
{
    return Opportunity::create(array_merge([
        'contact_id' => $contact->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $stage->id,
        'confidence' => 50,
    ], $overrides));
}

function leadhubTask(?Contact $contact, array $overrides = []): Task
{
    return Task::create(array_merge([
        'contact_id' => $contact?->id,
        'title' => 'Nachfassen',
    ], $overrides));
}

function leadhubEvent(Contact $contact, string $type, string $createdAt): Event
{
    return Event::create([
        'contact_id' => $contact->id,
        'type' => $type,
        'summary' => $type,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

/** The ten days the fixture lives in, bucketed by day. */
function leadhubQuery(array $filters = [], string $bucket = MetricQuery::BUCKET_DAY): MetricQuery
{
    return new MetricQuery(
        Period::between(Carbon::parse('2026-08-11')->startOfDay(), Carbon::parse('2026-08-20')->endOfDay()),
        $bucket,
        $filters,
    );
}

/**
 * A split as a map, so an expectation does not depend on the order of two rows
 * that hold the same number.
 *
 * Ordering is asserted separately, and only where the values differ — "largest
 * first" says nothing about which of two ones comes first, and a test that
 * demanded an answer would be testing the database's mood.
 *
 * @return array<string, int|float>
 */
function leadhubKeyed(array $rows): array
{
    $keyed = [];

    foreach ($rows as $row) {
        $keyed[$row['key'] ?? ''] = $row['value'];
    }

    ksort($keyed);

    return $keyed;
}

/** @return array<int, LeadhubMetric> */
function leadhubMetrics(): array
{
    return [
        new ContactsCreated,
        new ContactsActive,
        new OpportunitiesWon,
        new OpportunityValueWon,
        new TasksCompleted,
        new ScoreChanges,
    ];
}

// -- The six figures ---------------------------------------------------------

/**
 * Every figure at once, against hand-worked totals.
 *
 * One test rather than six, deliberately: they are read side by side on a
 * screen and have to agree with each other. Five arrivals against four on the
 * list is the pair worth catching — it is only right because one of the five
 * was a duplicate of somebody already there.
 */
it('reports the six figures the dashboard cannot', function (): void {
    leadhubInsightsFixture();
    $frage = leadhubQuery();

    expect((new ContactsCreated)->value($frage))->toBe(5, 'anna, bruno, clara, dora and the duplicate');
    expect((new ContactsActive)->value($frage))->toBe(4, 'the four minus dora (archived) and the duplicate, plus the one from July');

    expect((new OpportunitiesWon)->value($frage))->toBe(2, 'the lost one and the open one are not wins');
    // 1500.00 + 250.50 euros, in the minor units the CURRENCY unit promises.
    expect((new OpportunityValueWon)->value($frage))->toBe(175050, '(1500.00 + 250.50) × 100');

    expect((new TasksCompleted)->value($frage))->toBe(2, 'the cancelled one kept its stamp and is still not done');
    expect((new ScoreChanges)->value($frage))->toBe(3, 'the note is not a score change');
});

/** The handles are a contract. They end up in saved dashboards and in URLs. */
it('keeps the handles, units and group it promised', function (): void {
    $erwartet = [
        [ContactsCreated::class, 'leadhub.contacts_created', Unit::COUNT],
        [ContactsActive::class, 'leadhub.contacts_active', Unit::COUNT],
        [OpportunitiesWon::class, 'leadhub.opportunities_won', Unit::COUNT],
        [OpportunityValueWon::class, 'leadhub.opportunity_value_won', Unit::CURRENCY],
        [TasksCompleted::class, 'leadhub.tasks_completed', Unit::COUNT],
        [ScoreChanges::class, 'leadhub.score_changes', Unit::COUNT],
    ];

    foreach ($erwartet as [$klasse, $handle, $unit]) {
        $metrik = new $klasse;

        expect($metrik->handle())->toBe($handle);
        expect($metrik->unit())->toBe($unit);
        expect($metrik->group())->toBe(__('leadhub::insights.group'));
        expect($metrik->label())->not->toBe('');
        expect($metrik->description())->not->toBeEmpty();

        // The formatter cannot print money without knowing which money.
        expect($metrik->meta(leadhubQuery()))
            ->toBe($unit === Unit::CURRENCY ? ['currency' => 'EUR'] : []);
    }
});

it('names the group the same way in every metric and every language', function (): void {
    expect(__('leadhub::insights.group'))->toBe('CRM');

    app()->setLocale('de');
    expect(__('leadhub::insights.group'))->toBe('CRM');
});

it('takes the currency of the opportunity values from config', function (): void {
    config()->set('leadhub.currency', 'chf');

    expect((new OpportunityValueWon)->meta(leadhubQuery()))->toBe(['currency' => 'CHF']);
});

// -- Registration ------------------------------------------------------------

it('offers every metric to the analytics addon under its handle', function (): void {
    expect($this->insights->registered)->toMatchArray([
        'leadhub.contacts_created' => ContactsCreated::class,
        'leadhub.contacts_active' => ContactsActive::class,
        'leadhub.opportunities_won' => OpportunitiesWon::class,
        'leadhub.opportunity_value_won' => OpportunityValueWon::class,
        'leadhub.tasks_completed' => TasksCompleted::class,
        'leadhub.score_changes' => ScoreChanges::class,
    ]);
});

// -- Nothing to measure ------------------------------------------------------

/**
 * No tables, no answer — and not a zero.
 *
 * "Nothing to measure" and "measured nothing" are different statements, and a
 * zero for the first is the quiet kind of wrong: it puts a confident 0 on a
 * dashboard for a site whose CRM has never been migrated.
 */
it('cannot answer without the tables', function (): void {
    foreach (leadhubMetrics() as $metrik) {
        expect($metrik->available())->toBeTrue($metrik->handle().' should be available here');
    }

    // A second, empty database rather than dropping the tables in this one.
    // Dropping them would leave the suite unable to roll its own migrations
    // back, and a test that breaks its neighbours' teardown reports the wrong
    // failure everywhere afterwards.
    config()->set('database.connections.ohne_leadhub', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);

    $vorher = DB::getDefaultConnection();
    DB::purge('ohne_leadhub');
    DB::setDefaultConnection('ohne_leadhub');

    try {
        foreach (leadhubMetrics() as $metrik) {
            expect($metrik->available())->toBeFalse($metrik->handle().' answered without its table');
            expect($metrik->value(leadhubQuery()))->toBeNull();
            expect($metrik->series(leadhubQuery()))->toBe([]);
        }
    } finally {
        DB::setDefaultConnection($vorher);
    }
});

/**
 * The flat driver keeps its contacts in YAML, and these tables then lie.
 *
 * They exist wherever a migration once ran, and they stand empty. `hasTable`
 * alone would answer a confident zero for an install whose entire CRM is on
 * disk — the worst of the three possible answers, because it looks like data.
 */
it('cannot answer under the flat storage driver', function (): void {
    leadhubInsightsFixture();

    expect((new ContactsCreated)->value(leadhubQuery()))->toBe(5);

    config()->set('leadhub.storage.driver', 'flat');

    foreach (leadhubMetrics() as $metrik) {
        expect($metrik->available())->toBeFalse($metrik->handle().' answered off the wrong driver');
        expect($metrik->value(leadhubQuery()))->toBeNull();
    }
});

/**
 * A module that was never switched on has nothing to measure.
 *
 * Tasks, pipelines and scoring are opt-in and their tables exist after any
 * migration. Reporting zero deals for an install that does not use deals is a
 * statement about that install's sales that it never made.
 */
it('leaves out the figures whose module is switched off', function (): void {
    leadhubInsightsFixture();

    config()->set('leadhub.features.pipelines', false);
    config()->set('leadhub.features.tasks', false);
    config()->set('leadhub.features.scoring', false);

    expect((new OpportunitiesWon)->available())->toBeFalse();
    expect((new OpportunityValueWon)->available())->toBeFalse();
    expect((new TasksCompleted)->available())->toBeFalse();
    expect((new ScoreChanges)->available())->toBeFalse();

    // Contacts and their arrival are LeadHub's baseline and are always on.
    expect((new ContactsCreated)->available())->toBeTrue();
    expect((new ContactsActive)->available())->toBeTrue();
});

// -- The stock ---------------------------------------------------------------

/**
 * The stock is what stood at the end, not what happened during.
 *
 * Dora arrived on the 16th and was archived on the 18th, so she is in the count
 * on the 16th and 17th and out of it from the 18th. A figure that read the
 * archive flag as it stands today would have taken her out of the 16th as well,
 * and every past month would move a little every time somebody tidies up.
 */
it('counts the stock at the end of each bucket, including the quiet ones', function (): void {
    leadhubInsightsFixture();

    expect((new ContactsActive)->series(leadhubQuery()))->toBe([
        '2026-08-11' => 1,
        '2026-08-12' => 2,
        '2026-08-13' => 2,
        '2026-08-14' => 4,
        '2026-08-15' => 4,
        '2026-08-16' => 5,
        // The duplicate arrives on the 17th and is not a second person.
        '2026-08-17' => 5,
        '2026-08-18' => 4,
        '2026-08-19' => 4,
        '2026-08-20' => 4,
    ]);
});

/**
 * A stock has no empty buckets, and that is not the same rule as everywhere else.
 *
 * Insights fills a bucket a metric left out with a zero, which is right for a
 * flow and catastrophic here: the four quiet days above would draw the list
 * collapsing to nobody and recovering. So every bucket is emitted.
 */
it('emits a bucket for every day of the window even where nothing moved', function (): void {
    leadhubInsightsFixture();

    expect((new ContactsActive)->series(leadhubQuery()))->toHaveCount(10);
    expect((new ContactsCreated)->series(leadhubQuery()))->toHaveCount(4);
});

it('counts the stock monthly when the question is monthly', function (): void {
    leadhubInsightsFixture();

    $monatlich = new MetricQuery(
        Period::between(Carbon::parse('2026-07-01')->startOfDay(), Carbon::parse('2026-08-20')->endOfDay()),
        MetricQuery::BUCKET_MONTH,
    );

    expect((new ContactsActive)->series($monatlich))->toBe([
        '2026-07' => 1,
        '2026-08' => 4,
    ]);

    expect((new ContactsCreated)->series($monatlich))->toBe([
        '2026-07' => 1,
        '2026-08' => 5,
    ]);
});

/** Nobody at all is an empty chart, not a flat line reaching back to the epoch. */
it('draws no stock series at all when there is nobody', function (): void {
    expect((new ContactsActive)->value(leadhubQuery()))->toBe(0);
    expect((new ContactsActive)->series(new MetricQuery(Period::fromPreset('all'))))->toBe([]);
});

// -- Over time ---------------------------------------------------------------

it('puts each flow figure on the day it happened', function (): void {
    leadhubInsightsFixture();
    $frage = leadhubQuery();

    expect((new ContactsCreated)->series($frage))->toBe([
        '2026-08-12' => 1,
        '2026-08-14' => 2,
        '2026-08-16' => 1,
        '2026-08-17' => 1,
    ]);

    // On the day it was won, not the day it was opened.
    expect((new OpportunitiesWon)->series($frage))->toBe([
        '2026-08-13' => 1,
        '2026-08-17' => 1,
    ]);

    expect((new OpportunityValueWon)->series($frage))->toBe([
        '2026-08-13' => 150000,
        '2026-08-17' => 25050,
    ]);

    expect((new TasksCompleted)->series($frage))->toBe([
        '2026-08-12' => 1,
        '2026-08-19' => 1,
    ]);

    expect((new ScoreChanges)->series($frage))->toBe([
        '2026-08-12' => 2,
        '2026-08-19' => 1,
    ]);
});

// -- The splits --------------------------------------------------------------

/**
 * A contact without a source is a row keyed null, not a missing row.
 *
 * A report that quietly excludes rows is the hardest kind of wrong to notice:
 * the columns still add up among themselves, and only the total disagrees —
 * which is the number nobody re-adds.
 */
it('keeps the contacts with no attribution in the split', function (): void {
    leadhubInsightsFixture();
    $frage = leadhubQuery();

    $quellen = (new ContactsCreated)->breakdown($frage, 'source');

    expect(leadhubKeyed($quellen))->toBe(['' => 1, 'form' => 3, 'import' => 1]);
    expect(array_sum(array_column($quellen, 'value')))->toBe(5, 'the split has to add up to the figure it splits');

    // Largest first.
    expect($quellen[0]['key'])->toBe('form');

    $ohne = collect($quellen)->firstWhere('key', null);
    expect($ohne['label'])->toBe(__('leadhub::insights.missing.source'));

    // And the same for a campaign nobody set.
    $kampagnen = leadhubKeyed((new ContactsCreated)->breakdown($frage, 'utm_campaign'));
    expect($kampagnen)->toBe(['' => 3, 'sommer-2026' => 2]);

    $utm = (new ContactsCreated)->breakdown($frage, 'utm_source');
    expect(collect($utm)->firstWhere('key', null)['label'])
        ->toBe(__('leadhub::insights.missing.utm_source'));
});

/** A handle in a column, a name on the screen. */
it('labels a status the way the rest of the Control Panel does', function (): void {
    leadhubInsightsFixture();

    $zeilen = collect((new ContactsCreated)->breakdown(leadhubQuery(), 'status'))->keyBy('key');

    expect($zeilen['qualified']['label'])->toBe('Qualified');
    expect($zeilen['new']['label'])->toBe('New');
    expect($zeilen['new']['value'])->toBe(2);

    // A status the config does not know keeps its handle rather than vanishing.
    config()->set('leadhub.statuses', ['new' => 'New']);

    $ohneNamen = collect((new ContactsCreated)->breakdown(leadhubQuery(), 'status'))->keyBy('key');
    expect($ohneNamen['qualified']['label'])->toBe('qualified');
});

it('offers exactly the four splits it can answer', function (): void {
    leadhubInsightsFixture();

    expect(array_keys((new ContactsCreated)->breakdowns()))
        ->toBe(['status', 'source', 'utm_source', 'utm_campaign']);

    // A split nobody offers is empty, not an error.
    expect((new ContactsCreated)->breakdown(leadhubQuery(), 'weather'))->toBe([]);
});

it('orders a split by size and respects the limit', function (): void {
    leadhubInsightsFixture();

    $zeilen = (new ContactsCreated)->breakdown(leadhubQuery(), 'source', 1);

    expect($zeilen)->toHaveCount(1);
    expect($zeilen[0]['key'])->toBe('form');
});

// -- The future -------------------------------------------------------------

/**
 * "All time" has no upper bound, and these tables hold things that have not
 * happened yet.
 *
 * A contact imported with next month's date, a deal whose win was stamped
 * ahead: without a clamp the widest range reports every one of them as a fact
 * of the past, and the figure a person reads as "what we did" quietly becomes
 * "what we did and what somebody typed".
 *
 * The window presets have an upper bound of their own and are unaffected. It is
 * only the open-ended one that needs this, which is exactly why it went unseen
 * long enough to be worth a test.
 */
it('keeps the future out of the widest range', function (): void {
    leadhubInsightsFixture();

    leadhubContact('morgen@example.com', '2026-09-15 09:00:00', ['status' => 'new', 'source' => 'form']);

    $alles = new MetricQuery(Period::fromPreset('all'), MetricQuery::BUCKET_MONTH);

    expect((new ContactsCreated)->value($alles))
        ->toBe(6, 'the six that have happened, not the seventh that has not');

    expect((new ContactsCreated)->series($alles))->toBe([
        '2026-07' => 1,
        '2026-08' => 5,
    ]);

    // And the stock is asked as of this moment, not as of the end of time.
    expect((new ContactsActive)->value($alles))->toBe(4);
});

/**
 * The last second of the window is in the window, and midnight is not.
 *
 * The upper bound is 23:59:59.999999, and a database binding formats it as
 * `Y-m-d H:i:s` and drops the fraction — so a `<=` comparison threw away every
 * row in the final second on any column carrying one. LeadHub's columns are
 * whole seconds today, which is exactly why this is asserted rather than
 * assumed: the day a migration adds precision to one of them, nothing else
 * would notice.
 *
 * The row at midnight is the other half of the same rule. It belongs to the
 * period that starts there, not to the one that ends the moment before.
 */
it('keeps the last second of the window and gives midnight to the next one', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-21 06:00:00'));

    leadhubContact('sekunde@example.com', '2026-08-20 23:59:59', ['status' => 'new']);
    leadhubContact('mitternacht@example.com', '2026-08-21 00:00:00', ['status' => 'new']);

    expect((new ContactsCreated)->value(leadhubQuery()))->toBe(1, 'the last second is inside, midnight is not');
    expect((new ContactsCreated)->series(leadhubQuery()))->toBe(['2026-08-20' => 1]);

    $folgetag = new MetricQuery(
        Period::between(Carbon::parse('2026-08-21')->startOfDay(), Carbon::parse('2026-08-21')->endOfDay()),
    );

    expect((new ContactsCreated)->value($folgetag))->toBe(1, 'and midnight opens the next period');
});

// -- One clock ---------------------------------------------------------------

/**
 * The addon and the report keep time the same way, and this is where that is
 * proven rather than assumed.
 *
 * Every date column read here goes through Laravel's `datetime` cast, which
 * stores and reads in the application's timezone; Insights builds its `Period`
 * from `Carbon::now()`, which is the same clock. An addon that stored UTC
 * behind a cast of its own would be five hours out at every period boundary on
 * a site in Chicago, and the figures would be wrong only for the rows near the
 * edges — the hardest kind of wrong to notice.
 *
 * Half past eleven at night on the last day of the window is the row that
 * catches it: a conversion to UTC would push it into the 21st and out of both
 * the figure and its bucket.
 */
it('reads the same clock as the report it reports to', function (): void {
    $vorher = date_default_timezone_get();
    config()->set('app.timezone', 'America/Chicago');
    date_default_timezone_set('America/Chicago');

    try {
        // Late on the last evening of the window, so the row at half past
        // eleven is in the past and only the timezone can move it.
        Carbon::setTestNow(Carbon::parse('2026-08-20 23:55:00'));

        leadhubContact('spaet@example.com', '2026-08-20 23:30:00', ['status' => 'new']);
        leadhubContact('frueh@example.com', '2026-08-11 00:15:00', ['status' => 'new']);

        expect((new ContactsCreated)->value(leadhubQuery()))->toBe(2, 'both edges are inside the window');

        expect((new ContactsCreated)->series(leadhubQuery()))->toBe([
            '2026-08-11' => 1,
            '2026-08-20' => 1,
        ]);
    } finally {
        date_default_timezone_set($vorher);
    }
});

// -- One brand at a time -----------------------------------------------------

/**
 * These queries run past the global scope, so the brand is decided in the open.
 *
 * `DB::table()` does not carry the `HasBrand` scope every model in this addon
 * has, which is not an oversight to be worked around: a Control Panel screen
 * has an active brand and a scheduled command has none, and the two need
 * different answers. The rules the scope itself follows are mirrored, and this
 * is where that is held to account.
 */
it('counts one brand at a time when the install has more than one', function (): void {
    leadhubInsightsFixture();

    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    $zweite = Brand::create(['handle' => 'insights-b', 'name' => 'Zweite Marke']);

    BrandContext::setCurrent($zweite);
    leadhubContact('fremd@example.com', '2026-08-13 10:00:00', ['status' => 'new', 'source' => 'form']);

    // The second brand sees its own arrival and nothing of the first's.
    expect((new ContactsCreated)->value(leadhubQuery()))->toBe(1);
    expect((new ContactsActive)->value(leadhubQuery()))->toBe(1);

    BrandContext::setCurrent(Brand::default());

    expect((new ContactsCreated)->value(leadhubQuery()))->toBe(5, 'and the first sees none of the second\'s');
    expect((new ContactsActive)->value(leadhubQuery()))->toBe(4);
});

/**
 * No brand in hand is a nought, and the tile stays where it is.
 *
 * This test used to assert the opposite, and the opposite was wrong. A command
 * line has no active brand, and the scope's own answer for the *rows* in that
 * state is `fail_mode` — `closed` meaning none of them. This addon answered it
 * with `available() === false`, which took all six tiles off the screen; with
 * the newsletter addon doing the same thing that was twelve of them, and every
 * one of them disappeared without a word.
 *
 * `available()` says whether there is anything to measure — the tables, the
 * driver, the feature. A brand nobody has picked yet is none of those. So the
 * rows are still refused, the figure reads nought, and the tile stays: a reader
 * can make sense of a nought, and he cannot notice a tile that is not there.
 */
it('reads nought and keeps its place when no brand is resolved', function (): void {
    leadhubInsightsFixture();

    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();
    BrandContext::setCurrent(null);

    foreach (leadhubMetrics() as $metrik) {
        expect($metrik->available())->toBeTrue(
            $metrik->handle().' left the screen over a brand rather than over its tables',
        );
        expect($metrik->value(leadhubQuery()))->toBe(0, $metrik->handle().' summed across brands');
    }

    // The stock is the one that would have summed silently: `stockAt()` and
    // `earliestArrival()` build their own queries rather than going through the
    // windowed one, so the brand has to reach those too. Ten days of buckets,
    // every one of them a nought, and not one of them a null.
    $verlauf = (new ContactsActive)->series(leadhubQuery());

    expect($verlauf)->toHaveCount(10);
    expect(array_unique(array_values($verlauf)))->toBe([0]);

    // Where the install has said it prefers the other answer, the metric reads
    // across brands — the same thing the scope does with `fail_mode: open`.
    config()->set('brand-context.fail_mode', 'open');
    app('brand-context')->forget();
    BrandContext::setCurrent(null);

    expect((new ContactsCreated)->available())->toBeTrue();
    expect((new ContactsCreated)->value(leadhubQuery()))->toBe(5);
    expect((new ContactsActive)->value(leadhubQuery()))->toBe(4);
});

// -- Nothing happened --------------------------------------------------------

/**
 * A period in which nothing happened is a nought, not a silence.
 *
 * The flows genuinely measured nothing, which is an answer. The stock still
 * counts what stood there, which is a different answer, and it is not zero.
 */
it('answers a quiet period with noughts rather than with nulls', function (): void {
    leadhubInsightsFixture();

    $leer = new MetricQuery(
        Period::between(Carbon::parse('2026-09-01')->startOfDay(), Carbon::parse('2026-09-30')->endOfDay()),
    );

    expect((new ContactsCreated)->value($leer))->toBe(0);
    expect((new OpportunitiesWon)->value($leer))->toBe(0);
    expect((new OpportunityValueWon)->value($leer))->toBe(0);
    expect((new TasksCompleted)->value($leer))->toBe(0);
    expect((new ScoreChanges)->value($leer))->toBe(0);

    expect((new ContactsCreated)->series($leer))->toBe([]);

    // Everybody who was there in August is still there in September.
    expect((new ContactsActive)->value($leer))->toBe(4);
});
