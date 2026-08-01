<?php

use Goldnead\Leadhub\Listeners\CreateOrUpdateLeadFromSubmission;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Event as LeadHubEvent;
use Goldnead\Leadhub\Models\FormMapping;
use Goldnead\Leadhub\Repositories\Eloquent\EloquentContactRepository;
use Goldnead\Leadhub\Repositories\Eloquent\EloquentEventRepository;
use Goldnead\Leadhub\Repositories\Eloquent\EloquentFormMappingRepository;
use Goldnead\Leadhub\Repositories\Eloquent\EloquentTagRepository;
use Goldnead\Leadhub\Services\ContactResolver;
use Goldnead\Leadhub\Services\SubmissionMapper;
use Goldnead\Leadhub\Services\TagService;
use Goldnead\Leadhub\Services\TimelineService;
use Illuminate\Support\Collection;
use Statamic\Events\SubmissionCreated;

/**
 * Builds a fake Statamic submission object the listener accepts.
 * Mirrors the methods used: ->form()->handle(), ->data(), ->id().
 */
function fakeStatamicSubmission(string $handle, array $data, string $id = '1700000000.0'): object
{
    $form = new class($handle)
    {
        public function __construct(public string $handle) {}

        public function handle(): string
        {
            return $this->handle;
        }
    };

    return new class($form, $data, $id)
    {
        public function __construct(
            private object $form,
            private array $data,
            private string $id,
        ) {}

        public function form(): object
        {
            return $this->form;
        }

        public function data(): Collection
        {
            return collect($this->data);
        }

        public function id(): string
        {
            return $this->id;
        }
    };
}

beforeEach(function (): void {
    $timeline = new TimelineService(new EloquentEventRepository);
    $tagsRepo = new EloquentTagRepository;
    $this->listener = new CreateOrUpdateLeadFromSubmission(
        new SubmissionMapper,
        new ContactResolver(new EloquentContactRepository),
        $timeline,
        new TagService($tagsRepo, $timeline),
        new EloquentFormMappingRepository,
    );
});

it('creates a contact when a form submission arrives for an enabled mapping', function (): void {
    FormMapping::factory()->create([
        'form_handle' => 'contact',
        'enabled' => true,
        'email_field' => 'email',
        'first_name_field' => 'first_name',
    ]);

    $submission = fakeStatamicSubmission('contact', [
        'email' => 'jane@example.com',
        'first_name' => 'Jane',
    ]);

    $this->listener->handle(new SubmissionCreated($submission));

    expect(Contact::count())->toBe(1);
    $contact = Contact::first();
    expect($contact->email)->toBe('jane@example.com');
    expect($contact->first_name)->toBe('Jane');
});

it('attaches a second submission with the same email to the existing contact', function (): void {
    FormMapping::factory()->create([
        'form_handle' => 'contact',
        'enabled' => true,
        'email_field' => 'email',
    ]);

    $first = fakeStatamicSubmission('contact', ['email' => 'jane@example.com'], '1700000000.0');
    $second = fakeStatamicSubmission('contact', ['email' => 'JANE@example.com'], '1700000100.0');

    $this->listener->handle(new SubmissionCreated($first));
    $this->listener->handle(new SubmissionCreated($second));

    expect(Contact::count())->toBe(1);
    expect(LeadHubEvent::where('type', LeadHubEvent::TYPE_SUBMISSION_RECEIVED)->count())->toBe(2);
});

it('does nothing when the form has no mapping', function (): void {
    $submission = fakeStatamicSubmission('newsletter', ['email' => 'jane@example.com']);

    $this->listener->handle(new SubmissionCreated($submission));

    expect(Contact::count())->toBe(0);
});

it('does nothing when the mapping is disabled', function (): void {
    FormMapping::factory()->disabled()->create([
        'form_handle' => 'contact',
        'email_field' => 'email',
    ]);

    $submission = fakeStatamicSubmission('contact', ['email' => 'jane@example.com']);

    $this->listener->handle(new SubmissionCreated($submission));

    expect(Contact::count())->toBe(0);
});

it('skips submissions without an email value', function (): void {
    FormMapping::factory()->create([
        'form_handle' => 'contact',
        'enabled' => true,
        'email_field' => 'email',
    ]);

    $submission = fakeStatamicSubmission('contact', ['email' => '']);

    $this->listener->handle(new SubmissionCreated($submission));

    expect(Contact::count())->toBe(0);
});

it('skips submissions with an invalid email', function (): void {
    FormMapping::factory()->create([
        'form_handle' => 'contact',
        'enabled' => true,
        'email_field' => 'email',
    ]);

    $submission = fakeStatamicSubmission('contact', ['email' => 'not-an-email']);

    $this->listener->handle(new SubmissionCreated($submission));

    expect(Contact::count())->toBe(0);
});

it('attaches mapped tags to the contact during processing', function (): void {
    FormMapping::factory()->create([
        'form_handle' => 'contact',
        'enabled' => true,
        'email_field' => 'email',
        'tags_field' => 'topics',
        'default_tags' => ['lead'],
    ]);

    $submission = fakeStatamicSubmission('contact', [
        'email' => 'jane@example.com',
        'topics' => 'workshop, coaching',
    ]);

    $this->listener->handle(new SubmissionCreated($submission));

    $contact = Contact::first();
    $tagNames = $contact->tags()->pluck('name')->all();

    expect($tagNames)->toEqualCanonicalizing(['lead', 'workshop', 'coaching']);
});

it('records a submission_received timeline event', function (): void {
    FormMapping::factory()->create([
        'form_handle' => 'contact',
        'enabled' => true,
        'email_field' => 'email',
        'attach_full_submission' => true,
    ]);

    $submission = fakeStatamicSubmission('contact', [
        'email' => 'jane@example.com',
        'message' => 'Hello',
    ]);

    $this->listener->handle(new SubmissionCreated($submission));

    $event = LeadHubEvent::where('type', LeadHubEvent::TYPE_SUBMISSION_RECEIVED)->first();
    expect($event)->not->toBeNull();
    expect($event->payload['form_handle'])->toBe('contact');
    expect($event->payload['message'])->toBe('Hello');
});

it('updates last_processed_at on the mapping', function (): void {
    $mapping = FormMapping::factory()->create([
        'form_handle' => 'contact',
        'enabled' => true,
        'email_field' => 'email',
    ]);

    $submission = fakeStatamicSubmission('contact', ['email' => 'jane@example.com']);
    $this->listener->handle(new SubmissionCreated($submission));

    expect($mapping->fresh()->last_processed_at)->not->toBeNull();
});

it('captures attribution (UTM/referrer) when the feature is enabled', function (): void {
    config()->set('leadhub.features.attribution', true);

    FormMapping::factory()->create([
        'form_handle' => 'contact',
        'enabled' => true,
        'email_field' => 'email',
    ]);

    $submission = fakeStatamicSubmission('contact', [
        'email' => 'utm@example.com',
        'utm_source' => 'google',
        'utm_medium' => 'cpc',
        'utm_campaign' => 'spring',
        'referrer' => 'https://google.com/',
        'landing_page' => 'https://site.test/lp',
    ]);
    $this->listener->handle(new SubmissionCreated($submission));

    $contact = Contact::where('email', 'utm@example.com')->first();
    expect($contact)->not->toBeNull();
    expect($contact->utm_source)->toBe('google');
    expect($contact->utm_medium)->toBe('cpc');
    expect($contact->utm_campaign)->toBe('spring');
    expect($contact->referrer)->toBe('https://google.com/');
    expect($contact->landing_page)->toBe('https://site.test/lp');
});

it('keeps first-touch attribution across repeat submissions', function (): void {
    config()->set('leadhub.features.attribution', true);
    FormMapping::factory()->create(['form_handle' => 'contact', 'enabled' => true, 'email_field' => 'email']);

    $this->listener->handle(new SubmissionCreated(fakeStatamicSubmission('contact', [
        'email' => 'ft@example.com', 'utm_source' => 'newsletter',
    ])));
    $this->listener->handle(new SubmissionCreated(fakeStatamicSubmission('contact', [
        'email' => 'ft@example.com', 'utm_source' => 'google',
    ])));

    expect(Contact::where('email', 'ft@example.com')->first()->utm_source)->toBe('newsletter');
});

it('ignores attribution when the feature is disabled', function (): void {
    config()->set('leadhub.features.attribution', false);
    FormMapping::factory()->create(['form_handle' => 'contact', 'enabled' => true, 'email_field' => 'email']);

    $this->listener->handle(new SubmissionCreated(fakeStatamicSubmission('contact', [
        'email' => 'noutm@example.com', 'utm_source' => 'google',
    ])));

    expect(Contact::where('email', 'noutm@example.com')->first()->utm_source)->toBeNull();
});
