<?php

use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Leadhub\Models\Pipeline;
use Statamic\Facades\User;

/**
 * Covers the Kanban board route when the pipelines feature is enabled but no
 * pipeline has been created yet: the user should get a native empty state
 * (HTTP 200) instead of a hard 404.
 */
beforeEach(function (): void {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('Pipelines target the eloquent driver.');
    }

    $this->user = User::make()->email('board-empty@example.com')->makeSuper();
    $this->user->save();
    $this->actingAs($this->user);

    config()->set('leadhub.features.pipelines', true);
});

// Request as Inertia (X-Inertia header) so the response is the page JSON and
// we don't render the full CP blade layout, which the testbench can't bootstrap.
$inertia = ['X-Inertia' => 'true', 'X-Inertia-Version' => ''];

it('renders a 200 empty-state board page when no pipeline exists', function () use ($inertia): void {
    expect(Pipeline::query()->count())->toBe(0);

    $response = $this->get(cp_route('leadhub.pipelines.board'), $inertia)
        ->assertOk();

    $page = $response->json();
    expect($page['component'])->toBe('leadhub::Pipelines/Board')
        ->and($page['props']['pipeline'])->toBeNull()
        ->and($page['props']['pipelines'])->toBe([])
        ->and($page['props']['columns'])->toBe([])
        ->and($page['props'])->toHaveKey('manageUrl');
});

it('still 404s the board when the pipelines feature is disabled', function () use ($inertia): void {
    config()->set('leadhub.features.pipelines', false);

    $this->get(cp_route('leadhub.pipelines.board'), $inertia)
        ->assertNotFound();
});

it('renders the board with a real pipeline once one exists', function () use ($inertia): void {
    LeadHub::createPipeline('Sales', [
        ['name' => 'New'],
        ['name' => 'Won', 'is_terminal' => true, 'terminal_outcome' => 'won'],
    ]);

    $response = $this->get(cp_route('leadhub.pipelines.board'), $inertia)
        ->assertOk();

    $page = $response->json();
    expect($page['component'])->toBe('leadhub::Pipelines/Board')
        ->and($page['props']['pipeline']['name'])->toBe('Sales')
        ->and($page['props']['columns'])->toHaveCount(2);
});
