<?php

use Goldnead\Leadhub\Events\LeadHubCompanyCreated;
use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Leadhub\Models\Company;
use Goldnead\Leadhub\Models\Contact;
use Illuminate\Support\Facades\Event as EventFacade;

beforeEach(function () {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('Companies target the eloquent driver.');
    }
});

it('derives a domain and normalized name on create', function () {
    $company = Company::create(['name' => '  Acme   Corp ', 'website' => 'https://www.Acme.com/about']);

    expect($company->domain)->toBe('acme.com')
        ->and($company->name_normalized)->toBe('acme corp');
});

it('deduplicates companies by domain', function () {
    EventFacade::fake([LeadHubCompanyCreated::class]);

    $a = LeadHub::createCompany(['name' => 'Acme', 'website' => 'https://acme.com']);
    $b = LeadHub::createCompany(['name' => 'Acme Corporation', 'website' => 'http://www.acme.com/contact']);

    expect($b['id'])->toBe($a['id']);
    expect(Company::query()->count())->toBe(1);
    EventFacade::assertDispatchedTimes(LeadHubCompanyCreated::class, 1);
});

it('deduplicates companies by normalized name when no domain', function () {
    $a = LeadHub::createCompany(['name' => 'Globex GmbH']);
    $b = LeadHub::createCompany(['name' => 'globex   gmbh']);

    expect($b['id'])->toBe($a['id']);
    expect(Company::query()->count())->toBe(1);
});

it('links a company to a contact as primary', function () {
    $contact = LeadHub::create(['email' => 'cfo@acme.com']);

    LeadHub::linkCompany($contact['id'], ['name' => 'Acme', 'website' => 'acme.com'], 'CFO', primary: true);

    $model = Contact::find($contact['id']);
    expect($model->companies()->count())->toBe(1);

    $pivot = $model->companies()->first()->pivot;
    expect($pivot->relationship_label)->toBe('CFO')
        ->and((bool) $pivot->is_primary)->toBeTrue();
});

it('demotes the previous primary company when a new primary is linked', function () {
    $contact = LeadHub::create(['email' => 'multi@example.com']);

    LeadHub::linkCompany($contact['id'], ['name' => 'First Co', 'website' => 'first.com'], primary: true);
    LeadHub::linkCompany($contact['id'], ['name' => 'Second Co', 'website' => 'second.com'], primary: true);

    $model = Contact::find($contact['id']);
    $primaries = $model->companies()->wherePivot('is_primary', true)->get();

    expect($primaries)->toHaveCount(1)
        ->and($primaries->first()->name)->toBe('Second Co');
});
