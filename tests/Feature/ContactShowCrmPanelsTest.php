<?php

use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Leadhub\Models\Company;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Services\CompanyResolver;
use Goldnead\Leadhub\Services\TaskService;
use Statamic\Facades\User;

/**
 * The contact detail page must show the CRM records linked to the contact.
 *
 * ContactController::show passed no props for companies, tasks or
 * opportunities, so the page showed none of them however the feature flags were
 * set. The free-text `company` column is a different thing from a linked
 * Company record — both are asserted here so the two can't be conflated again.
 */
beforeEach(function (): void {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('CRM-core panels target the eloquent driver.');
    }

    $this->user = User::make()->email('crm-panels@example.com')->makeSuper();
    $this->user->save();
    $this->actingAs($this->user);

    config()->set('leadhub.features.companies', true);
    config()->set('leadhub.features.tasks', true);
    config()->set('leadhub.features.pipelines', true);

    $this->contact = Contact::create([
        'email' => 'panels@example.com',
        'company' => 'Free text from the form',
    ]);
});

function showProps($test, Contact $contact): array
{
    $response = $test->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('leadhub.contacts.show', $contact->uuid));

    $response->assertStatus(200);

    return json_decode($response->getContent(), true)['props'] ?? [];
}

it('lists the companies linked to the contact', function (): void {
    $company = Company::create(['name' => 'Acme Industries', 'website' => 'https://acme.test']);
    app(CompanyResolver::class)->link($this->contact, $company, 'Employer', primary: true);

    $props = showProps($this, $this->contact);

    expect($props['linkedCompanies'])->toHaveCount(1)
        ->and($props['linkedCompanies'][0]['name'])->toBe('Acme Industries')
        ->and($props['linkedCompanies'][0]['is_primary'])->toBeTrue()
        ->and($props['linkedCompanies'][0]['relationship_label'])->toBe('Employer');
});

it('keeps the free-text company distinct from the linked company', function (): void {
    $company = Company::create(['name' => 'Acme Industries']);
    app(CompanyResolver::class)->link($this->contact, $company);

    $props = showProps($this, $this->contact);

    // Same page, two unrelated values — the text field must not be overwritten
    // by the link, and the link must not be inferred from the text.
    expect($props['contact']['company'])->toBe('Free text from the form')
        ->and($props['linkedCompanies'][0]['name'])->toBe('Acme Industries');
});

it('lists the tasks of the contact', function (): void {
    app(TaskService::class)->create(['title' => 'Call back', 'due_at' => now()->addDay()], $this->contact);

    $props = showProps($this, $this->contact);

    expect($props['tasks'])->toHaveCount(1)
        ->and($props['tasks'][0]['title'])->toBe('Call back')
        ->and($props['tasks'][0]['is_completed'])->toBeFalse();
});

it('lists the opportunities of the contact', function (): void {
    LeadHub::createPipeline('Sales', [
        ['name' => 'New'],
        ['name' => 'Won', 'is_terminal' => true, 'terminal_outcome' => 'won'],
    ]);
    LeadHub::upsertOpportunity($this->contact->id, 'sales', [
        'title' => 'Website relaunch',
        'value_estimate' => 4200,
    ]);

    $props = showProps($this, $this->contact);

    expect($props['opportunities'])->toHaveCount(1)
        ->and($props['opportunities'][0]['title'])->toBe('Website relaunch')
        ->and($props['opportunities'][0]['value_estimate'])->toEqual(4200.0)
        ->and($props['opportunities'][0]['stage_name'])->toBe('New');
});

it('reports which CRM modules are on so the page can render honest empty states', function (): void {
    config()->set('leadhub.features.tasks', false);

    $props = showProps($this, $this->contact);

    expect($props['crmFeatures']['companies'])->toBeTrue()
        ->and($props['crmFeatures']['tasks'])->toBeFalse()
        ->and($props['tasks'])->toBe([]);
});
