<?php

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Events\LeadHubContactsMerged;
use Goldnead\Leadhub\Events\LeadHubSourceIngested;
use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Leadhub\Listeners\ScoreContactOnActivity;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Event;
use Goldnead\Leadhub\Services\ContactResolver;
use Goldnead\Leadhub\Support\ContactDto;
use Goldnead\Leadhub\Support\PhoneNormalizer;
use Illuminate\Support\Facades\Event as EventFacade;

// CRM-core contact features target the eloquent driver.
beforeEach(function () {
    if (config('leadhub.storage.driver') === 'flat') {
        test()->markTestSkipped('Contact CRM fields target the eloquent driver.');
    }
});

it('normalizes phone numbers consistently', function () {
    expect(PhoneNormalizer::normalize('+49 (170) 123-4567'))->toBe('+491701234567')
        ->and(PhoneNormalizer::normalize('0170 / 123 45 67'))->toBe('01701234567')
        ->and(PhoneNormalizer::normalize('   '))->toBeNull();
});

it('persists phone_normalized on create', function () {
    LeadHub::create(['email' => 'p@example.com', 'phone' => '+49 170 1234567']);

    $contact = Contact::query()->where('email', 'p@example.com')->first();
    expect($contact->phone_normalized)->toBe('+491701234567');
});

it('deduplicates by phone when the email differs', function () {
    $resolver = app(ContactResolver::class);

    [$first] = $resolver->resolveOrCreate(new ContactDto(email: 'a@example.com', phone: '+49 170 1234567'));
    [$second, $created] = $resolver->resolveOrCreate(new ContactDto(email: 'b@example.com', phone: '+49-170-1234567'));

    expect($second->id)->toBe($first->id)
        ->and($created)->toBeFalse();
});

it('merges a duplicate contact into the survivor', function () {
    EventFacade::fake([LeadHubContactsMerged::class]);

    $winner = LeadHub::create(['email' => 'winner@example.com', 'first_name' => 'Win']);
    $loser = LeadHub::create(['email' => 'loser@example.com', 'company' => 'Acme Inc']);

    LeadHub::addTag($loser['id'], 'imported');
    LeadHub::addNote($loser['id'], 'Note on loser');
    LeadHub::createFollowUp($loser['id'], ['due_in_days' => 2]);

    $merged = LeadHub::merge($loser['id'], $winner['id']);

    expect($merged['id'])->toBe($winner['id'])
        // empty winner field backfilled from loser
        ->and($merged['company'])->toBe('Acme Inc')
        ->and($merged['tags'])->toContain('imported');

    $winnerModel = Contact::find($winner['id']);
    $loserModel = Contact::find($loser['id']);

    expect($winnerModel->notes()->count())->toBe(1)
        ->and($winnerModel->followups()->count())->toBe(1)
        ->and($loserModel->merged_into_contact_id)->toBe($winnerModel->id)
        ->and($loserModel->isMerged())->toBeTrue();

    // Survivor carries a merge timeline entry; loser is excluded from unmerged().
    expect(Event::query()->where('type', Event::TYPE_CONTACTS_MERGED)->where('contact_id', $winnerModel->id)->exists())->toBeTrue()
        ->and(Contact::query()->unmerged()->pluck('id'))->not->toContain($loserModel->id);

    EventFacade::assertDispatched(LeadHubContactsMerged::class);
});

it('awards engagement points only when scoring is enabled', function () {
    config()->set('leadhub.features.scoring', true);
    config()->set('leadhub.scoring.events', ['purchase.completed' => 10]);

    // The addon registers this via $listen at boot; the test harness does not
    // fire Statamic::booted, so wire it explicitly to exercise the real path.
    EventFacade::listen(
        LeadHubSourceIngested::class,
        ScoreContactOnActivity::class,
    );

    LeadHub::ingest([
        'email' => 'scored@example.com',
        'type' => 'purchase.completed',
        'dedupe_key' => 'order:scored:1',
    ]);

    $contact = app(ContactRepository::class)->findByEmailNormalized('scored@example.com');
    expect((int) $contact->engagement_score)->toBe(10);
});

it('does not score when the feature is disabled', function () {
    config()->set('leadhub.features.scoring', false);

    LeadHub::ingest([
        'email' => 'unscored@example.com',
        'type' => 'purchase.completed',
        'dedupe_key' => 'order:unscored:1',
    ]);

    $contact = app(ContactRepository::class)->findByEmailNormalized('unscored@example.com');
    expect((int) $contact->engagement_score)->toBe(0);
});
