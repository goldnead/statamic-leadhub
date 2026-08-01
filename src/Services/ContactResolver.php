<?php

namespace Goldnead\Leadhub\Services;

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Events\LeadHubContactCreated;
use Goldnead\Leadhub\Events\LeadHubContactUpdated;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Support\ContactDto;
use Goldnead\Leadhub\Support\EmailNormalizer;
use Goldnead\Leadhub\Support\PhoneNormalizer;

class ContactResolver
{
    public function __construct(protected ContactRepository $contacts) {}

    /**
     * Find an existing contact by normalized email or create a new one.
     * Returns a tuple-like array: [Contact $contact, bool $wasCreated].
     */
    public function resolveOrCreate(ContactDto $dto): array
    {
        if (! $dto->hasEmail()) {
            throw new \InvalidArgumentException('Cannot resolve contact: ContactDto has no email.');
        }

        $normalized = EmailNormalizer::normalize($dto->email);
        $existing = $this->contacts->findByEmailNormalized($normalized);

        // Secondary dedup by normalized phone — catches the same person
        // reaching out under a different email address.
        if (! $existing && $dto->phone) {
            $phoneNormalized = PhoneNormalizer::normalize($dto->phone);

            if ($phoneNormalized) {
                $existing = $this->contacts->findByPhoneNormalized($phoneNormalized);
            }
        }

        if ($existing) {
            $this->updateExisting($existing, $dto);

            return [$existing, false];
        }

        $contact = $this->createNew($dto, $normalized);

        return [$contact, true];
    }

    protected function createNew(ContactDto $dto, string $normalized): Contact
    {
        $attributes = $dto->toContactAttributes();
        $attributes['email'] = $dto->email;
        $attributes['email_normalized'] = $normalized;
        $attributes['status'] = $dto->defaultStatus ?? config('leadhub.default_status', 'new');
        $attributes['consent'] = $dto->consent;
        $attributes['consent_at'] = $dto->consent ? now() : null;
        $attributes['last_activity_at'] = now();

        $contact = $this->contacts->create($attributes);

        event(new LeadHubContactCreated($contact));

        return $contact;
    }

    /**
     * PRD §15.3: Never overwrite non-empty contact fields from a form
     * submission unless the overwrite config flag is set.
     */
    protected function updateExisting(Contact $contact, ContactDto $dto): void
    {
        $overwrite = (bool) config('leadhub.overwrite_existing_fields_from_submissions', false);
        $attributes = $dto->toContactAttributes();
        $changed = false;

        foreach ($attributes as $key => $value) {
            if ($key === 'email') {
                continue;
            }

            $current = $contact->getAttribute($key);
            $isEmpty = ($current === null || $current === '');

            if ($overwrite || $isEmpty) {
                $contact->setAttribute($key, $value);
                $changed = true;
            }
        }

        if ($dto->consent && ! $contact->consent) {
            $contact->consent = true;
            $contact->consent_at = now();
            $changed = true;
        }

        $contact->last_activity_at = now();

        $this->contacts->save($contact);

        if ($changed) {
            event(new LeadHubContactUpdated($contact));
        }
    }

    public function touchActivity(Contact $contact): void
    {
        $this->contacts->touchActivity($contact);
    }
}
