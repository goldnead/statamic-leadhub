<?php

use Goldnead\Leadhub\Events\LeadHubCompanyCreated;
use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Leadhub\Models\Company;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Opportunity;
use Goldnead\Leadhub\Models\Pipeline;
use Goldnead\Leadhub\Services\CompanyResolver;
use Illuminate\Support\Facades\Event;
use Statamic\Facades\User;

/**
 * Companies can be created, edited and deleted from the Control Panel.
 *
 * Everything here goes through the real route (request -> controller), not the
 * model: the gap this closes was never a missing model, it was a missing HTTP
 * surface. A test against Company::create() would have passed for a year while
 * the CP had no button.
 */
beforeEach(function (): void {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('CRM-core CP screens target the eloquent driver.');
    }

    $this->user = User::make()->email('company-crud@example.com')->makeSuper();
    $this->user->save();
    $this->actingAs($this->user);

    config()->set('leadhub.features.companies', true);
    config()->set('leadhub.features.pipelines', true);
});

it('renders the create form', function (): void {
    $response = $this->withHeaders(['X-Inertia' => 'true'])->get(cp_route('leadhub.companies.create'));

    $response->assertStatus(200);
    expect(json_decode($response->getContent(), true)['component'])->toBe('leadhub::Companies/Create');
});

it('creates a company from the CP', function (): void {
    Event::fake([LeadHubCompanyCreated::class]);

    $this->post(cp_route('leadhub.companies.store'), [
        'name' => 'Muster GmbH',
        'website' => 'https://muster.example',
        'industry' => 'Manufacturing',
    ])->assertRedirect();

    $company = Company::query()->where('name', 'Muster GmbH')->first();

    expect($company)->not->toBeNull()
        ->and($company->domain)->toBe('muster.example')
        ->and($company->name_normalized)->toBe('muster gmbh');

    // The webhook-manager bridge and the segment listeners hang off this
    // event. A CP path that skipped it would create records the rest of the
    // system never hears about.
    Event::assertDispatched(LeadHubCompanyCreated::class);
});

it('refuses a company without a name and says so on the field', function (): void {
    $this->post(cp_route('leadhub.companies.store'), ['name' => ''])
        ->assertSessionHasErrors('name');

    expect(Company::query()->count())->toBe(0);
});

it('refuses a duplicate company name', function (): void {
    Company::create(['name' => 'Muster GmbH']);

    $this->post(cp_route('leadhub.companies.store'), ['name' => '  muster   gmbh '])
        ->assertSessionHasErrors('name');

    expect(Company::query()->count())->toBe(1);
});

it('refuses a duplicate website domain', function (): void {
    Company::create(['name' => 'Alpha', 'website' => 'https://www.acme.example/about']);

    $this->post(cp_route('leadhub.companies.store'), [
        'name' => 'Beta',
        'website' => 'acme.example',
    ])->assertSessionHasErrors('website');

    expect(Company::query()->count())->toBe(1);
});

it('edits a company from the CP', function (): void {
    $company = Company::create(['name' => 'Old Name', 'industry' => 'Retail']);

    $this->patch(cp_route('leadhub.companies.update', $company->id), [
        'name' => 'New Name',
        'industry' => 'Wholesale',
    ])->assertRedirect();

    $company->refresh();

    expect($company->name)->toBe('New Name')
        ->and($company->industry)->toBe('Wholesale')
        // The model's updating hook has to keep the dedup key in step.
        ->and($company->name_normalized)->toBe('new name');
});

it('does not treat the edited company as its own duplicate', function (): void {
    $company = Company::create(['name' => 'Same Name', 'website' => 'https://same.example']);

    $this->patch(cp_route('leadhub.companies.update', $company->id), [
        'name' => 'Same Name',
        'website' => 'https://same.example',
        'industry' => 'Logistics',
    ])->assertSessionHasNoErrors();

    expect($company->refresh()->industry)->toBe('Logistics');
});

it('deletes a company that nothing hangs on', function (): void {
    $company = Company::create(['name' => 'Disposable GmbH']);

    $this->delete(cp_route('leadhub.companies.destroy', $company->id))
        ->assertRedirect(cp_route('leadhub.companies.index'));

    expect(Company::query()->whereKey($company->id)->exists())->toBeFalse();
});

it('refuses to delete a company that still has contacts', function (): void {
    $company = Company::create(['name' => 'Linked GmbH']);
    $contact = Contact::create(['email' => 'linked@example.com']);
    app(CompanyResolver::class)->link($contact, $company);

    $this->delete(cp_route('leadhub.companies.destroy', $company->id))
        ->assertSessionHasErrors('company');

    expect(Company::query()->whereKey($company->id)->exists())->toBeTrue();
});

it('refuses to delete a company that still has opportunities', function (): void {
    $company = Company::create(['name' => 'Deal GmbH']);
    $contact = Contact::create(['email' => 'deal@example.com']);
    LeadHub::createPipeline('Sales', [['name' => 'New']]);
    $pipeline = Pipeline::query()->firstOrFail();

    Opportunity::create([
        'contact_id' => $contact->id,
        'company_id' => $company->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $pipeline->stages()->first()->id,
        'title' => 'Deal',
    ]);

    $response = $this->delete(cp_route('leadhub.companies.destroy', $company->id));

    $response->assertSessionHasErrors('company');
    expect(session('errors')->first('company'))->toContain('1');
    expect(Company::query()->whereKey($company->id)->exists())->toBeTrue();
});

it('404s every write route when the companies feature is off', function (): void {
    $company = Company::create(['name' => 'Hidden GmbH']);
    config()->set('leadhub.features.companies', false);

    $this->withHeaders(['X-Inertia' => 'true'])->get(cp_route('leadhub.companies.create'))->assertStatus(404);
    $this->post(cp_route('leadhub.companies.store'), ['name' => 'X'])->assertStatus(404);
    $this->patch(cp_route('leadhub.companies.update', $company->id), ['name' => 'X'])->assertStatus(404);
    $this->delete(cp_route('leadhub.companies.destroy', $company->id))->assertStatus(404);
});

it('refuses the write routes without the manage permission', function (): void {
    $plain = User::make()->email('company-nobody@example.com');
    $plain->save();
    $this->actingAs($plain);

    $company = Company::create(['name' => 'Guarded GmbH']);

    $this->withHeaders(['X-Inertia' => 'true'])->get(cp_route('leadhub.companies.create'))->assertStatus(403);
    $this->post(cp_route('leadhub.companies.store'), ['name' => 'X'])->assertStatus(403);
    $this->delete(cp_route('leadhub.companies.destroy', $company->id))->assertStatus(403);

    expect(Company::query()->count())->toBe(1);
});
