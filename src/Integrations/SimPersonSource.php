<?php

namespace Goldnead\Leadhub\Integrations;

use Goldnead\BrandContext\Contracts\ProvidesSimPersons;
use Goldnead\Leadhub\Models\Contact;

/**
 * The CRM as a place the dry run can copy a real person from ("wie bei Person X").
 *
 * Read only. Nothing is written, saved, touched or logged here, and no line of a log carries a name or an address.
 * What this source hands over is the tags, the status, the name, the address, the scalar custom fields and whether
 * she asked not to be written to. Notes, revenue and activities stay where they are.
 */
class SimPersonSource implements ProvidesSimPersons
{
    private const MAX_SEARCH = 10;

    private const MAX_TAGS = 30;

    private const MAX_CUSTOM = 30;

    public function personSourceId(): string
    {
        return 'leadhub';
    }

    public function personsAuthorized($user): bool
    {
        return $user !== null && $user->can('view leadhub contacts');
    }

    public function searchPersons(string $query, int $limit): array
    {
        // A pattern character typed by hand would turn the search into a listing; the lookup is a contains-match.
        $needle = trim(str_replace(['%', '_'], '', $query));
        $limit = min($limit, self::MAX_SEARCH);

        if ($needle === '' || $limit < 1) {
            return [];
        }

        $like = '%'.$needle.'%';

        return Contact::query()
            ->active()
            ->where(function ($q) use ($like): void {
                $q->where('email', 'like', $like)
                    ->orWhere('full_name', 'like', $like)
                    ->orWhere('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like);
            })
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (Contact $contact): array => [
                'key' => (string) $contact->id,
                'label' => $this->label($contact),
            ])
            ->values()
            ->all();
    }

    public function identifyPerson(string $key): ?array
    {
        if (preg_match('/^[1-9][0-9]*$/', $key) !== 1) {
            return null;
        }

        $contact = Contact::query()->find((int) $key);

        if ($contact === null) {
            return null;
        }

        $email = strtolower(trim((string) $contact->email));

        return $email === ''
            ? ['id' => $contact->id]
            : ['email' => $email, 'id' => $contact->id];
    }

    public function personFacet(array $identity): array
    {
        $id = (int) ($identity['id'] ?? 0);
        $email = strtolower(trim((string) ($identity['email'] ?? '')));

        if ($id < 1 || $email === '') {
            return [];
        }

        $contact = Contact::query()->find($id);

        // Only the person the identity names: the same id with another address is not her.
        if ($contact === null || strtolower(trim((string) $contact->email)) !== $email) {
            return [];
        }

        $traits = [
            'contact.tags' => $contact->tags()
                ->orderBy('leadhub_tags.name')
                ->limit(self::MAX_TAGS)
                ->get()
                ->pluck('name')
                ->values()
                ->all(),
        ];

        $columns = [
            'status' => 'contact.status',
            'first_name' => 'contact.first_name',
            'last_name' => 'contact.last_name',
            'full_name' => 'contact.name',
            'email' => 'contact.email',
        ];

        foreach ($columns as $column => $trait) {
            $value = $contact->getAttribute($column);

            if (is_string($value) && trim($value) !== '') {
                $traits[$trait] = $value;
            }
        }

        $custom = array_filter(
            (array) $contact->getAttribute('custom_fields'),
            fn ($value): bool => is_scalar($value),
        );

        foreach (array_slice($custom, 0, self::MAX_CUSTOM, true) as $key => $value) {
            $traits['contact.custom.'.$key] = $value;
        }

        return [
            'traits' => $traits,
            'unsubscribed' => (bool) $contact->do_not_contact,
        ];
    }

    private function label(Contact $contact): string
    {
        $name = trim((string) $contact->getAttribute('full_name'));

        if ($name === '') {
            $name = trim((string) $contact->getAttribute('first_name').' '.(string) $contact->getAttribute('last_name'));
        }

        $email = trim((string) $contact->email);

        if ($name === '') {
            return $email;
        }

        return $email === '' ? $name : "{$name} ({$email})";
    }
}
