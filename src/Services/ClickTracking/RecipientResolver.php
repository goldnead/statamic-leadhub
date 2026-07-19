<?php

namespace Goldnead\Leadhub\Services\ClickTracking;

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Support\EmailNormalizer;

/**
 * Resolves the recipient of a tracked email link back to a LeadHub contact.
 *
 * A tracked link carries a stable contact identifier (the contact UUID) and,
 * optionally, the recipient email as a fallback. Both are covered by the URL
 * signature, so neither can be forged once the link is signed. Lookup is
 * best-effort: never throws, returns null when nothing matches.
 */
class RecipientResolver
{
    public function __construct(protected ContactRepository $contacts)
    {
    }

    /**
     * Resolve a contact by stable UUID first, then by (normalized) email.
     */
    public function resolve(?string $contactId = null, ?string $email = null): ?Contact
    {
        if ($contactId !== null && $contactId !== '') {
            $contact = $this->contacts->findByUuid($contactId);

            if ($contact instanceof Contact) {
                return $contact;
            }
        }

        if ($email !== null && $email !== '') {
            $normalized = EmailNormalizer::normalize($email);

            $contact = $this->contacts->findByEmailNormalized($normalized);

            if ($contact instanceof Contact) {
                return $contact;
            }
        }

        return null;
    }
}
