<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Events\LeadHubFollowupDue;
use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Leadhub\Models\Segment;
use Goldnead\Leadhub\Services\FollowupService;
use Illuminate\Support\Facades\Event;

/**
 * The three scheduled commands, under multi-brand.
 *
 * A console run has no session, so no brand is current, and the multi-brand
 * global scope then fails closed. Before these commands iterated brands they
 * saw nothing at all and said so in the most reassuring way available:
 *
 *   Swept 0 segment(s): 0 entered, 0 left.
 *   Fired 0 follow-up due event(s).
 *   No due or overdue follow-ups — nothing to send.
 *
 * Every one of those reads as "nothing to do" and meant "I could not see
 * anything". The symptom in the Control Panel was a segment list stuck at 0
 * members for rules that clearly matched contacts, with no error anywhere.
 *
 * A single-brand install was never affected, which is why this survived.
 */
beforeEach(function (): void {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('These commands query the eloquent store.');
    }

    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();
});

/** Two brands, each with one contact matching a status-based segment. */
function twoBrandsWithSegments(): array
{
    $a = Brand::create(['handle' => 'sweep-a', 'name' => 'Sweep A']);
    $b = Brand::create(['handle' => 'sweep-b', 'name' => 'Sweep B']);

    foreach ([$a, $b] as $brand) {
        BrandContext::runFor($brand, function () use ($brand) {
            Segment::create([
                'name' => 'Qualified',
                'handle' => 'qualified-'.$brand->handle,
                'is_active' => true,
                'rules' => [
                    'match' => 'all',
                    'conditions' => [
                        ['type' => 'field', 'field' => 'status', 'operator' => 'eq', 'value' => 'qualified'],
                    ],
                ],
            ]);

            LeadHub::create([
                'email' => 'lead@'.$brand->handle.'.example',
                'status' => 'qualified',
            ]);
        });
    }

    return [$a, $b];
}

it('sweeps segments in every brand, not just the ambient one', function (): void {
    [$a, $b] = twoBrandsWithSegments();

    $this->artisan('leadhub:segments:sweep')->assertExitCode(0);

    foreach ([$a, $b] as $brand) {
        $members = BrandContext::runFor(
            $brand,
            fn () => Segment::query()->where('handle', 'qualified-'.$brand->handle)->firstOrFail()->contacts()->count(),
        );

        expect($members)->toBe(1, "brand {$brand->handle} was not swept");
    }
});

it('restricts the sweep to one brand when asked', function (): void {
    [$a, $b] = twoBrandsWithSegments();

    $this->artisan('leadhub:segments:sweep --brand=sweep-a')->assertExitCode(0);

    $count = fn (Brand $brand) => BrandContext::runFor(
        $brand,
        fn () => Segment::query()->where('handle', 'qualified-'.$brand->handle)->firstOrFail()->contacts()->count(),
    );

    expect($count($a))->toBe(1)
        ->and($count($b))->toBe(0);
});

it('fires follow-up-due events in every brand', function (): void {
    Event::fake([LeadHubFollowupDue::class]);

    $a = Brand::create(['handle' => 'due-a', 'name' => 'Due A']);
    $b = Brand::create(['handle' => 'due-b', 'name' => 'Due B']);

    foreach ([$a, $b] as $brand) {
        BrandContext::runFor($brand, function () use ($brand) {
            $contact = LeadHub::create(['email' => 'due@'.$brand->handle.'.example']);
            app(FollowupService::class)->set(
                app(ContactRepository::class)->find($contact['id']),
                now()->subDays(2),
            );
        });
    }

    $this->artisan('leadhub:followups:due')->assertExitCode(0);

    // One per brand. Before the fix this was zero, and the command still
    // exited successfully.
    Event::assertDispatchedTimes(LeadHubFollowupDue::class, 2);
});

it('offers a --brand option on all three scheduled commands', function (): void {
    // The regression is cheap to reintroduce by copying an older command, so
    // assert the option exists rather than only its effect.
    foreach ([
        'leadhub:segments:sweep',
        'leadhub:followups:due',
        'leadhub:followups:digest',
    ] as $command) {
        expect(app('Illuminate\Contracts\Console\Kernel')->all()[$command]->getDefinition()->hasOption('brand'))
            ->toBeTrue("{$command} has no --brand option");
    }
});
