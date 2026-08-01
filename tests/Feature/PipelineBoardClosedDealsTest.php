<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Opportunity;
use Goldnead\Leadhub\Models\Pipeline;
use Goldnead\Leadhub\Models\Stage;
use Goldnead\Leadhub\Services\StageTransitionService;
use Illuminate\Support\Str;
use Statamic\Facades\User;

/**
 * Closed opportunities on the Kanban board.
 *
 * The board query filtered on open(), so winning a deal removed it from the
 * board entirely and left the win column summing to 0 — for the person using
 * it, indistinguishable from data loss. Closed deals now stay in their terminal
 * stage for a selectable window, and the stage total counts them.
 */
beforeEach(function (): void {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('The pipeline board targets the eloquent driver.');
    }

    $this->user = User::make()->email('board-closed@example.com')->makeSuper();
    $this->user->save();
    $this->actingAs($this->user);

    config()->set('leadhub.features.pipelines', true);
});

function boardProps($test, array $query = []): array
{
    $response = $test->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('leadhub.pipelines.board').'?'.http_build_query($query));

    $response->assertStatus(200);

    return json_decode($response->getContent(), true)['props'] ?? [];
}

function winOpportunity(Opportunity $opportunity): Opportunity
{
    $won = Stage::query()
        ->where('pipeline_id', $opportunity->pipeline_id)
        ->where('terminal_outcome', 'won')
        ->firstOrFail();

    app(StageTransitionService::class)->transition($opportunity, $won);

    return $opportunity->refresh();
}

/**
 * One pipeline (New / Won / Lost) plus one deal moved into the Won stage.
 *
 * The pipeline name is a parameter because the brand migration's down() path
 * restores a GLOBAL unique on `leadhub_pipelines.slug`; two brands holding the
 * same slug would break the rollback that RefreshDatabase performs, not the
 * assertion under test.
 */
function seedPipelineWithWonDeal(float $value = 4200.0, string $pipelineName = 'Sales'): Opportunity
{
    LeadHub::createPipeline($pipelineName, [
        ['name' => 'New'],
        ['name' => 'Won', 'is_terminal' => true, 'terminal_outcome' => 'won'],
        ['name' => 'Lost', 'is_terminal' => true, 'terminal_outcome' => 'lost'],
    ]);

    $pipeline = Pipeline::query()->where('slug', Str::slug($pipelineName))->firstOrFail();
    $contact = Contact::create(['email' => Str::slug($pipelineName).'-deal@example.com']);

    $opportunity = Opportunity::query()->create([
        'contact_id' => $contact->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $pipeline->stages()->where('slug', 'new')->firstOrFail()->id,
        'title' => 'Won deal',
        'value_estimate' => $value,
        'status' => Opportunity::STATUS_OPEN,
    ]);

    return winOpportunity($opportunity);
}

it('keeps a won deal visible on the board', function (): void {
    seedPipelineWithWonDeal();

    $columns = collect(boardProps($this)['columns']);
    $wonColumn = $columns->firstWhere('slug', 'won');

    expect($wonColumn['cards'])->toHaveCount(1)
        ->and($wonColumn['cards'][0]['title'])->toBe('Won deal')
        ->and($wonColumn['cards'][0]['is_closed'])->toBeTrue()
        ->and($wonColumn['cards'][0]['outcome'])->toBe('won');
});

it('sums the win column to the actual value instead of zero', function (): void {
    seedPipelineWithWonDeal(4200.0);

    $wonColumn = collect(boardProps($this)['columns'])->firstWhere('slug', 'won');

    expect($wonColumn['total_value'])->toEqual(4200.0)
        ->and($wonColumn['closed_value'])->toEqual(4200.0);
});

it('reports won and lost totals for the board', function (): void {
    seedPipelineWithWonDeal(4200.0);

    expect(boardProps($this)['totals']['won'])->toEqual(4200.0);
});

it('hides closed deals again when the filter is set to open only', function (): void {
    seedPipelineWithWonDeal();

    $wonColumn = collect(boardProps($this, ['closed' => 'none'])['columns'])->firstWhere('slug', 'won');

    expect($wonColumn['cards'])->toBe([])
        ->and($wonColumn['total_value'])->toEqual(0.0);
});

it('drops a deal closed longer ago than the window, and finds it again with a wider one', function (): void {
    $opportunity = seedPipelineWithWonDeal();

    // Closed 200 days ago: outside the 30-day default, inside "all".
    $opportunity->forceFill([
        'closed_at' => now()->subDays(200),
        'won_at' => now()->subDays(200),
    ])->save();

    $default = collect(boardProps($this)['columns'])->firstWhere('slug', 'won');
    $all = collect(boardProps($this, ['closed' => 'all'])['columns'])->firstWhere('slug', 'won');

    expect($default['cards'])->toBe([])
        ->and($all['cards'])->toHaveCount(1);
});

it('falls back to the default window for an unknown filter value', function (): void {
    seedPipelineWithWonDeal();

    expect(boardProps($this, ['closed' => 'nonsense'])['closedWindow'])->toBe('30d');
});

it('does not show another brand\'s closed deals', function (): void {
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    $brandA = Brand::create(['handle' => 'board-a', 'name' => 'Board A']);
    $brandB = Brand::create(['handle' => 'board-b', 'name' => 'Board B']);

    BrandContext::runFor($brandA, fn () => seedPipelineWithWonDeal(1000.0, 'Sales A'));
    BrandContext::runFor($brandB, fn () => seedPipelineWithWonDeal(9999.0, 'Sales B'));

    // Widening the board to closed deals must not widen it across brands.
    BrandContext::setCurrent($brandA);
    $columns = collect(boardProps($this, ['closed' => 'all'])['columns']);
    $wonColumn = $columns->firstWhere('slug', 'won');

    expect($wonColumn['cards'])->toHaveCount(1)
        ->and($wonColumn['total_value'])->toEqual(1000.0);

    BrandContext::setCurrent($brandB);
    $wonColumnB = collect(boardProps($this, ['closed' => 'all'])['columns'])->firstWhere('slug', 'won');

    expect($wonColumnB['cards'])->toHaveCount(1)
        ->and($wonColumnB['total_value'])->toEqual(9999.0);
});
