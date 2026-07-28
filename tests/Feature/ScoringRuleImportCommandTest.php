<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Leadhub\Models\ScoringRule;
use Goldnead\Leadhub\Services\ScoringService;

/**
 * The migration of an existing configuration.
 *
 * This is the part of the release that can do real damage. Scoring steers
 * segments and segments steer who receives mail, so an upgrade after which the
 * engine computes different numbers than before — without anybody asking for
 * it — is the worst available outcome. Two mechanisms prevent it and both are
 * asserted here:
 *
 * 1. the fallback: with an empty table, the config file still decides;
 * 2. the import: after it runs, the table says exactly what the config said.
 *
 * Plus the properties that make the command safe to run: a dry run writes
 * nothing, a second run changes nothing, and an edited rule survives a re-run.
 */
beforeEach(function (): void {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('Scoring rules live in a table; the flat driver has none.');
    }

    config()->set('leadhub.features.scoring', true);

    // A point table that is not the shipped default, so a passing test cannot
    // be a coincidence of matching numbers.
    config()->set('leadhub.scoring.default', 2);
    config()->set('leadhub.scoring.events', [
        'submission_received' => 3,
        'purchase.completed' => 15,
        'booking.confirmed' => 5,
    ]);
});

/** What the engine awards for each configured type plus one unconfigured one. */
function scoringSnapshot(): array
{
    $service = app(ScoringService::class);

    return [
        'submission_received' => $service->pointsFor('submission_received'),
        'purchase.completed' => $service->pointsFor('purchase.completed'),
        'booking.confirmed' => $service->pointsFor('booking.confirmed'),
        'nothing.configured' => $service->pointsFor('nothing.configured'),
    ];
}

it('keeps computing from config while no rule exists', function (): void {
    expect(ScoringRule::query()->count())->toBe(0)
        ->and(scoringSnapshot())->toBe([
            'submission_received' => 3,
            'purchase.completed' => 15,
            'booking.confirmed' => 5,
            'nothing.configured' => 2,
        ]);
});

it('writes nothing on a dry run', function (): void {
    $before = scoringSnapshot();

    $this->artisan('leadhub:scoring:import --dry-run')
        ->expectsOutputToContain('Dry run')
        ->assertExitCode(0);

    expect(ScoringRule::query()->count())->toBe(0)
        ->and(scoringSnapshot())->toBe($before);
});

it('produces exactly the same scoring after the import as before it', function (): void {
    // The whole safety claim in one assertion.
    $before = scoringSnapshot();

    $this->artisan('leadhub:scoring:import')->assertExitCode(0);

    expect(ScoringRule::query()->count())->toBe(4)
        ->and(scoringSnapshot())->toBe($before);
});

it('imports the config default as the catch-all rule', function (): void {
    $this->artisan('leadhub:scoring:import')->assertExitCode(0);

    $catchAll = ScoringRule::query()->where('event_type', ScoringRule::CATCH_ALL)->firstOrFail();

    expect($catchAll->points)->toBe(2)
        ->and($catchAll->enabled)->toBeTrue();

    // And it now beats the config default, so a brand can raise its own
    // baseline instead of inheriting one global value.
    $catchAll->update(['points' => 9]);
    expect(app(ScoringService::class)->pointsFor('nothing.configured'))->toBe(9);
});

it('is idempotent', function (): void {
    $this->artisan('leadhub:scoring:import')->assertExitCode(0);
    $first = ScoringRule::query()->orderBy('event_type')->get()->map->only(['event_type', 'points'])->all();

    $this->artisan('leadhub:scoring:import')->assertExitCode(0);
    $second = ScoringRule::query()->orderBy('event_type')->get()->map->only(['event_type', 'points'])->all();

    expect(ScoringRule::query()->count())->toBe(4)
        ->and($second)->toBe($first);
});

it('does not undo a CP edit on a second run', function (): void {
    // A rule that differs from the config file is a rule somebody edited. An
    // import that overwrote it would be a scheduled way to silently revert the
    // Control Panel.
    $this->artisan('leadhub:scoring:import')->assertExitCode(0);

    ScoringRule::query()->where('event_type', 'purchase.completed')->firstOrFail()->update(['points' => 40]);

    $this->artisan('leadhub:scoring:import')
        ->expectsOutputToContain('use --force to overwrite')
        ->assertExitCode(0);

    expect(ScoringRule::query()->where('event_type', 'purchase.completed')->first()->points)->toBe(40);
});

it('overwrites an edited rule only when forced', function (): void {
    $this->artisan('leadhub:scoring:import')->assertExitCode(0);
    ScoringRule::query()->where('event_type', 'purchase.completed')->firstOrFail()->update(['points' => 40]);

    $this->artisan('leadhub:scoring:import --force --dry-run')->assertExitCode(0);
    expect(ScoringRule::query()->where('event_type', 'purchase.completed')->first()->points)->toBe(40);

    $this->artisan('leadhub:scoring:import --force')->assertExitCode(0);
    expect(ScoringRule::query()->where('event_type', 'purchase.completed')->first()->points)->toBe(15);
});

it('imports into every brand separately', function (): void {
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    $a = Brand::create(['handle' => 'import-a', 'name' => 'Import A']);
    $b = Brand::create(['handle' => 'import-b', 'name' => 'Import B']);

    $this->artisan('leadhub:scoring:import')->assertExitCode(0);

    // Every brand gets its own copy — the default brand plus the two created
    // here. Without that, brand B would silently fall back to config while
    // brand A had rules, and the two would drift apart without a signal.
    foreach ([$a, $b] as $brand) {
        expect(ScoringRule::query()->forBrand($brand)->count())->toBe(4);
    }

    // And they are independent from that point on.
    BrandContext::runFor($a, fn () => ScoringRule::query()
        ->where('event_type', 'purchase.completed')->firstOrFail()->update(['points' => 99]));

    expect(BrandContext::runFor($a, fn () => app(ScoringService::class)->pointsFor('purchase.completed')))->toBe(99)
        ->and(BrandContext::runFor($b, fn () => app(ScoringService::class)->pointsFor('purchase.completed')))->toBe(15);
});

it('restricts to one brand when asked', function (): void {
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    $a = Brand::create(['handle' => 'only-a', 'name' => 'Only A']);
    $b = Brand::create(['handle' => 'only-b', 'name' => 'Only B']);

    $this->artisan('leadhub:scoring:import --brand=only-a')->assertExitCode(0);

    expect(ScoringRule::query()->forBrand($a)->count())->toBe(4)
        ->and(ScoringRule::query()->forBrand($b)->count())->toBe(0);
});
