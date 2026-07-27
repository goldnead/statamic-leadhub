<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Opportunity;
use Goldnead\Leadhub\Models\Pipeline;
use Goldnead\Leadhub\Models\Stage;
use Statamic\Facades\User;

/**
 * Editing and reordering pipeline stages.
 *
 * "Add stage" only ever appended, and nothing could be renamed, reordered or
 * removed afterwards. A stage that landed behind the terminal ones sat in the
 * wrong place on the board, and the only repair was rebuilding the pipeline.
 */
beforeEach(function (): void {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('Pipelines target the eloquent driver.');
    }

    $this->user = User::make()->email('stages@example.com')->makeSuper();
    $this->user->save();
    $this->actingAs($this->user);

    config()->set('leadhub.features.pipelines', true);

    LeadHub::createPipeline('Sales', [
        ['name' => 'New'],
        ['name' => 'Won', 'is_terminal' => true, 'terminal_outcome' => 'won'],
        ['name' => 'Lost', 'is_terminal' => true, 'terminal_outcome' => 'lost'],
    ]);

    $this->pipeline = Pipeline::query()->firstOrFail();
});

function stageNames(Pipeline $pipeline): array
{
    return $pipeline->stages()->orderBy('sort_order')->pluck('name')->all();
}

it('appends a stage to an existing pipeline', function (): void {
    $this->post(cp_route('leadhub.pipelines.stages.store', $this->pipeline->id), [
        'name' => 'Proposal',
    ])->assertStatus(302);

    expect(stageNames($this->pipeline))->toBe(['New', 'Won', 'Lost', 'Proposal']);
});

it('moves an appended stage in front of the terminal ones', function (): void {
    $this->post(cp_route('leadhub.pipelines.stages.store', $this->pipeline->id), ['name' => 'Proposal']);

    $byName = $this->pipeline->stages()->pluck('id', 'name');

    $this->post(cp_route('leadhub.pipelines.stages.reorder', $this->pipeline->id), [
        'stage_ids' => [$byName['New'], $byName['Proposal'], $byName['Won'], $byName['Lost']],
    ])->assertStatus(302);

    expect(stageNames($this->pipeline))->toBe(['New', 'Proposal', 'Won', 'Lost']);
});

it('makes the board columns follow the saved stage order', function (): void {
    $byName = $this->pipeline->stages()->pluck('id', 'name');

    $this->post(cp_route('leadhub.pipelines.stages.reorder', $this->pipeline->id), [
        'stage_ids' => [$byName['New'], $byName['Lost'], $byName['Won']],
    ])->assertStatus(302);

    $response = $this->withHeaders(['X-Inertia' => 'true'])->get(cp_route('leadhub.pipelines.board'));
    $columns = json_decode($response->getContent(), true)['props']['columns'];

    expect(array_column($columns, 'name'))->toBe(['New', 'Lost', 'Won']);
});

it('refuses a partial reorder rather than applying half of it', function (): void {
    $byName = $this->pipeline->stages()->pluck('id', 'name');

    $this->post(cp_route('leadhub.pipelines.stages.reorder', $this->pipeline->id), [
        'stage_ids' => [$byName['Lost'], $byName['New']],   // 'Won' missing
    ])->assertStatus(422);

    expect(stageNames($this->pipeline))->toBe(['New', 'Won', 'Lost']);
});

it('refuses to reorder using a stage from another pipeline', function (): void {
    LeadHub::createPipeline('Support', [['name' => 'Inbox'], ['name' => 'Closed']]);
    $foreign = Stage::query()->where('slug', 'inbox')->firstOrFail();
    $byName = $this->pipeline->stages()->pluck('id', 'name');

    $this->post(cp_route('leadhub.pipelines.stages.reorder', $this->pipeline->id), [
        'stage_ids' => [$byName['New'], $byName['Won'], $foreign->id],
    ])->assertStatus(422);

    expect(stageNames($this->pipeline))->toBe(['New', 'Won', 'Lost']);
});

it('renames a stage', function (): void {
    $stage = $this->pipeline->stages()->where('slug', 'new')->firstOrFail();

    $this->patch(cp_route('leadhub.pipelines.stages.update', [$this->pipeline->id, $stage->id]), [
        'name' => 'Inbound',
    ])->assertStatus(302);

    expect($stage->fresh()->name)->toBe('Inbound');
});

it('clears the outcome when a stage stops being terminal', function (): void {
    $won = $this->pipeline->stages()->where('slug', 'won')->firstOrFail();

    $this->patch(cp_route('leadhub.pipelines.stages.update', [$this->pipeline->id, $won->id]), [
        'is_terminal' => false,
    ])->assertStatus(302);

    expect($won->fresh()->is_terminal)->toBeFalse()
        ->and($won->fresh()->terminal_outcome)->toBeNull();
});

it('deletes an empty stage and closes the gap in the order', function (): void {
    $lost = $this->pipeline->stages()->where('slug', 'lost')->firstOrFail();

    $this->delete(cp_route('leadhub.pipelines.stages.destroy', [$this->pipeline->id, $lost->id]))
        ->assertStatus(302);

    expect(stageNames($this->pipeline))->toBe(['New', 'Won'])
        ->and($this->pipeline->stages()->orderBy('sort_order')->pluck('sort_order')->all())->toBe([0, 1]);
});

it('refuses to delete a stage that still holds opportunities', function (): void {
    $stage = $this->pipeline->stages()->where('slug', 'new')->firstOrFail();
    $contact = Contact::create(['email' => 'occupied@example.com']);

    Opportunity::query()->create([
        'contact_id' => $contact->id,
        'pipeline_id' => $this->pipeline->id,
        'stage_id' => $stage->id,
        'title' => 'Still open',
        'status' => Opportunity::STATUS_OPEN,
    ]);

    $this->from(cp_route('leadhub.pipelines.manage'))
        ->delete(cp_route('leadhub.pipelines.stages.destroy', [$this->pipeline->id, $stage->id]))
        ->assertSessionHasErrors('stage');

    expect($stage->fresh())->not->toBeNull();
});

it('exposes stage ids and management urls on the manage screen', function (): void {
    $response = $this->withHeaders(['X-Inertia' => 'true'])->get(cp_route('leadhub.pipelines.manage'));
    $stages = json_decode($response->getContent(), true)['props']['pipelines'][0]['stages'];

    expect($stages[0])->toHaveKeys(['id', 'sort_order', 'update_url', 'delete_url', 'opportunities_count']);
});

it('requires the settings permission for stage editing', function (): void {
    $plain = User::make()->email('stage-viewer@example.com');
    $plain->save();

    $stage = $this->pipeline->stages()->where('slug', 'new')->firstOrFail();

    $this->actingAs($plain)
        ->patch(cp_route('leadhub.pipelines.stages.update', [$this->pipeline->id, $stage->id]), ['name' => 'Nope'])
        ->assertStatus(403);

    expect($stage->fresh()->name)->toBe('New');
});

it('cannot reach a pipeline from another brand', function (): void {
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    $brandA = Brand::create(['handle' => 'stage-a', 'name' => 'Stage A']);
    $brandB = Brand::create(['handle' => 'stage-b', 'name' => 'Stage B']);

    $pipelineA = BrandContext::runFor($brandA, function () {
        LeadHub::createPipeline('Brand A pipeline', [['name' => 'One'], ['name' => 'Two']]);

        return Pipeline::query()->where('slug', 'brand-a-pipeline')->firstOrFail();
    });

    $stage = BrandContext::runFor($brandA, fn () => $pipelineA->stages()->firstOrFail());

    BrandContext::setCurrent($brandB);

    $this->patch(cp_route('leadhub.pipelines.stages.update', [$pipelineA->id, $stage->id]), ['name' => 'Hijacked'])
        ->assertStatus(404);

    expect(BrandContext::runFor($brandA, fn () => $stage->fresh()->name))->toBe('One');
});
