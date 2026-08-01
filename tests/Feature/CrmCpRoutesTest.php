<?php

use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Leadhub\Models\Pipeline;
use Statamic\Facades\User;

beforeEach(function (): void {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('CRM-core CP screens target the eloquent driver.');
    }

    $this->user = User::make()->email('crm-cp@example.com')->makeSuper();
    $this->user->save();
    $this->actingAs($this->user);

    config()->set('leadhub.features.companies', true);
    config()->set('leadhub.features.tasks', true);
    config()->set('leadhub.features.pipelines', true);
});

function inertiaComponentName($response): ?string
{
    if (! $response->headers->get('X-Inertia')) {
        return null;
    }

    return json_decode($response->getContent(), true)['component'] ?? null;
}

it('renders the companies index when enabled', function (): void {
    $response = $this->withHeaders(['X-Inertia' => 'true'])->get(cp_route('leadhub.companies.index'));

    $response->assertStatus(200);
    expect(inertiaComponentName($response))->toBe('leadhub::Companies/Index');
});

it('404s the companies index when the feature is disabled', function (): void {
    config()->set('leadhub.features.companies', false);

    $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('leadhub.companies.index'))
        ->assertStatus(404);
});

it('renders the tasks index when enabled', function (): void {
    $response = $this->withHeaders(['X-Inertia' => 'true'])->get(cp_route('leadhub.tasks.index'));

    $response->assertStatus(200);
    expect(inertiaComponentName($response))->toBe('leadhub::Tasks/Index');
});

it('renders the pipeline board when a pipeline exists', function (): void {
    LeadHub::createPipeline('Sales', [
        ['name' => 'New'],
        ['name' => 'Won', 'is_terminal' => true, 'terminal_outcome' => 'won'],
    ]);

    $response = $this->withHeaders(['X-Inertia' => 'true'])->get(cp_route('leadhub.pipelines.board'));

    $response->assertStatus(200);
    expect(inertiaComponentName($response))->toBe('leadhub::Pipelines/Board');
});

it('renders the pipeline management screen', function (): void {
    $response = $this->withHeaders(['X-Inertia' => 'true'])->get(cp_route('leadhub.pipelines.manage'));

    $response->assertStatus(200);
    expect(inertiaComponentName($response))->toBe('leadhub::Pipelines/Manage');
});

it('creates a pipeline with stages from the management screen', function (): void {
    $this->post(cp_route('leadhub.pipelines.store'), [
        'name' => 'Onboarding',
        'stages' => [
            ['name' => 'Kickoff', 'is_terminal' => false],
            ['name' => 'Live', 'is_terminal' => true, 'terminal_outcome' => 'won'],
        ],
    ])->assertRedirect();

    expect(Pipeline::where('slug', 'onboarding')->exists())->toBeTrue();
    expect(Pipeline::where('slug', 'onboarding')->first()->stages()->count())->toBe(2);
});
