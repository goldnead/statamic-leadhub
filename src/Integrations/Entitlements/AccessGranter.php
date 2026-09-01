<?php

namespace Goldnead\Leadhub\Integrations\Entitlements;

use Goldnead\Leadhub\Integrations\Timeline\NeighbourSource;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Support\EmailNormalizer;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * "Give this person access to that" — from the contact screen.
 *
 * Goes through the entitlements **facade**, never the model: `grant()` is
 * idempotent on the (subject, product, source, ref) tuple, refuses to widen a
 * revoked grant, and fires the events every other consumer listens for. A
 * row written by hand would do none of that and the access would exist
 * without anyone having been told (see `feedback-seeder-durch-die-fassade`).
 *
 * The product list comes from payments' catalogue when payments is installed
 * — the catalogue knows which grant slugs a product carries, and a bundle
 * carries several. Without payments, the choice is what entitlements has
 * already granted to anyone: a slug that was never used is not offered,
 * because nothing would open for it.
 *
 * Every neighbour is a string class name; see
 * {@see NeighbourSource}.
 */
class AccessGranter
{
    public const SOURCE = 'manual';

    protected const FACADE = '\Goldnead\Entitlements\Facades\Entitlements';

    protected const REFERENCE = '\Goldnead\Entitlements\Support\SubjectReference';

    protected const CATALOGUE = '\Goldnead\StatamicPayments\Support\Catalogue';

    protected const IDENTITY = '\Goldnead\IdentityContracts\Identity';

    public function available(): bool
    {
        if (! class_exists(ltrim(static::FACADE, '\\')) || ! class_exists(ltrim(static::REFERENCE, '\\'))) {
            return false;
        }

        try {
            return Schema::hasTable('entitlements');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * What can be granted, for a select.
     *
     * @return list<array{value: string, label: string, slugs: list<string>}>
     */
    public function options(): array
    {
        $options = [];

        foreach ($this->catalogue() as $handle => $entry) {
            $handle = (string) $handle;
            if ($handle === '' || ! is_array($entry)) {
                continue;
            }

            $slugs = $this->slugList($entry['grants'] ?? null) ?: [$handle];
            $options[] = [
                'value' => $handle,
                'label' => (string) ($entry['name'] ?? $handle),
                'slugs' => $slugs,
            ];
        }

        if ($options === []) {
            foreach ($this->knownSlugs() as $slug) {
                $options[] = ['value' => $slug, 'label' => $slug, 'slugs' => [$slug]];
            }
        }

        usort($options, fn ($a, $b) => strcasecmp($a['label'], $b['label']));

        return $options;
    }

    /** @return list<string> */
    public function slugsFor(string $product): array
    {
        foreach ($this->options() as $option) {
            if ($option['value'] === $product) {
                return $option['slugs'];
            }
        }

        return [];
    }

    /**
     * Grant every slug the product carries.
     *
     * The facade is idempotent and **leaves a revoked grant revoked** — a
     * retried webhook must not undo a refund. From this button that silence
     * would be a lie: the user clicked "grant" and nothing opened. So the
     * revoked case is refused before anything is written, with a pointer to
     * entitlements' own "restore"; and a grant that already existed is
     * reported as such, so the caller can say "had access already" instead of
     * recording a grant that did not happen.
     *
     * @return array{ids: list<int|string>, created: list<string>, existing: list<string>}
     */
    public function grant(Contact $contact, string $product, ?string $note, ?Authenticatable $user): array
    {
        $email = EmailNormalizer::normalize($contact->getAttribute('email'));
        $uuid = (string) $contact->getAttribute('uuid');

        if ($email === null) {
            throw new InvalidArgumentException(__('leadhub::contacts.access_grant.no_email'));
        }

        $slugs = $this->slugsFor($product);

        if ($slugs === []) {
            throw new InvalidArgumentException(__('leadhub::contacts.access_grant.unknown_product'));
        }

        $meta = array_filter([
            'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
            'granted_by' => $user ? (string) $user->getAuthIdentifier() : null,
            'granted_by_label' => $user ? $this->userLabel($user) : null,
            'contact_uuid' => $uuid,
            'product' => $product,
            'via' => 'leadhub',
        ], fn ($v) => $v !== null && $v !== '');

        $revoked = $this->revokedAmong($email, $slugs);

        if ($revoked !== []) {
            throw new InvalidArgumentException(__('leadhub::contacts.access_grant.revoked', ['slugs' => implode(', ', $revoked)]));
        }

        $subject = $this->subjectFor($email);
        $actor = $this->actorFor($user);
        $ids = [];
        $created = [];
        $existing = [];

        foreach ($slugs as $slug) {
            $grant = $this->write($subject, $slug, 'leadhub:'.$uuid, $meta, $actor);
            $ids[] = $grant->getKey();

            if ($this->isNew($grant)) {
                $created[] = $slug;
            } else {
                $existing[] = $slug;
            }
        }

        return ['ids' => $ids, 'created' => $created, 'existing' => $existing];
    }

    /**
     * The slugs among `$slugs` this address holds a **revoked** grant for.
     *
     * Asked before writing, because the facade would return such a grant
     * untouched and the click would have done nothing visible.
     *
     * @param  list<string>  $slugs
     * @return list<string>
     */
    protected function revokedAmong(string $email, array $slugs): array
    {
        $facade = static::FACADE;

        try {
            $grants = $facade::query()
                ->where('subject_type', 'email')
                ->where('subject_id', $email)
                ->whereIn('product_slug', $slugs)
                ->get();
        } catch (\Throwable) {
            return [];
        }

        $revoked = [];
        foreach ($grants as $grant) {
            if ($this->stateOf($grant) === 'revoked') {
                $revoked[] = (string) $grant->product_slug;
            }
        }

        return array_values(array_unique($revoked));
    }

    /** The entitlement manager's verdict on a grant, as its enum's string value. */
    protected function stateOf(object $grant): string
    {
        $facade = static::FACADE;
        $state = $facade::stateOf($grant);

        return is_object($state) && property_exists($state, 'value') ? (string) $state->value : (string) $state;
    }

    /**
     * Whether `write()` made or changed this grant, as opposed to handing an
     * existing one back. A pending grant claimed to active counts as new —
     * that is a transition somebody asked for.
     */
    protected function isNew(object $grant): bool
    {
        if (($grant->wasRecentlyCreated ?? false) === true) {
            return true;
        }

        return method_exists($grant, 'wasChanged') && $grant->wasChanged();
    }

    /**
     * The one call that touches the neighbour's write side.
     *
     * @param  array<string, mixed>  $meta
     */
    protected function write(mixed $subject, string $slug, string $sourceRef, array $meta, mixed $actor): object
    {
        $facade = static::FACADE;

        return $facade::grant(
            subject: $subject,
            productSlug: $slug,
            source: static::SOURCE,
            sourceRef: $sourceRef,
            meta: $meta,
            actor: $actor,
        );
    }

    protected function subjectFor(string $email): mixed
    {
        $reference = static::REFERENCE;

        return new $reference('email', $email);
    }

    protected function actorFor(?Authenticatable $user): mixed
    {
        $identity = static::IDENTITY;

        if ($user === null || ! class_exists(ltrim($identity, '\\')) || ! method_exists($identity, 'user')) {
            return null;
        }

        try {
            return $identity::user((string) $user->getAuthIdentifier(), $this->userEmail($user), $this->userLabel($user));
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    protected function catalogue(): array
    {
        $catalogue = static::CATALOGUE;

        if (! class_exists(ltrim($catalogue, '\\'))) {
            return [];
        }

        try {
            $all = app($catalogue)->all();
        } catch (\Throwable) {
            return [];
        }

        return is_array($all) ? $all : [];
    }

    /** @return list<string> */
    protected function knownSlugs(): array
    {
        $facade = static::FACADE;

        try {
            return $facade::query()
                ->distinct()
                ->orderBy('product_slug')
                ->pluck('product_slug')
                ->filter(fn ($slug) => is_string($slug) && $slug !== '')
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return list<string> */
    protected function slugList(mixed $grants): array
    {
        if (is_string($grants)) {
            $grants = preg_split('/[\s,]+/', $grants) ?: [];
        }

        if (! is_array($grants)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($slug) => is_scalar($slug) ? trim((string) $slug) : '',
            $grants,
        ))));
    }

    protected function userLabel(Authenticatable $user): ?string
    {
        foreach (['name', 'email'] as $attribute) {
            $value = method_exists($user, $attribute) ? $user->{$attribute}() : ($user->{$attribute} ?? null);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    protected function userEmail(Authenticatable $user): ?string
    {
        $value = method_exists($user, 'email') ? $user->email() : ($user->email ?? null);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
