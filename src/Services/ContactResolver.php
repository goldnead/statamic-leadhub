<?php

namespace Goldnead\Leadhub\Services;

use Goldnead\Leadhub\Events\LeadHubContactCreated;
use Goldnead\Leadhub\Events\LeadHubContactUpdated;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Support\ContactDto;
use Goldnead\Leadhub\Support\EmailNormalizer;

class ContactResolver
{
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

        $contact = Contact::query()
            ->where('email_normalized', $normalized)
            ->first();

        if ($contact) {
            $this->updateExisting($contact, $dto);

            return [$contact, false];
        }

        $contact = $this->createNew($dto, $normalized);

        return [$contact, true];
    }

    protected function createNew(ContactDto $dto, string $normalized): Contact
    {
        $contact = new Contact();
        $contact->fill($dto->toContactAttributes());
        $contact->email = $dto->email;
        $contact->email_normalized = $normalized;
        $contact->status = $dto->defaultStatus ?? config('leadhub.default_status', 'new');
        $contact->consent = $dto->consent;
        $contact->consent_at = $dto->consent ? now() : null;
        $contact->last_activity_at = now();
        $contact->save();

        event(new LeadHubContactCreated($contact));

        return $contact;
    }

    /**
     * Update an existing contact from a new submission.
     *
     * Rule (PRD §15.3): "Never overwrite non-empty contact fields from a form
     * submission unless setting allows it." Only fills fields that are currently
     * empty on the contact, unless the overwrite config flag is set.
     */
    protected function updateExisting(Contact $contact, ContactDto $dto): void
    {
        $overwrite = (bool) config('leadhub.overwrite_existing_fields_from_submissions', false);
        $attributes = $dto->toContactAttributes();
        $changed = false;

        foreach ($attributes as $key => $value) {
            if ($key === 'email') {
                // Never overwrite the canonical email; we matched on it.
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
        $contact->save();

        if ($changed) {
            event(new LeadHubContactUpdated($contact));
        }
    }

    /**
     * Bump the last_activity_at timestamp without other changes.
     */
    public function touchActivity(Contact $contact): void
    {
        $contact->last_activity_at = now();
        $contact->save();
    }
}
