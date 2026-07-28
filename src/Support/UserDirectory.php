<?php

namespace Goldnead\Leadhub\Support;

use Goldnead\BrandContext\Facades\BrandMembers;
use Statamic\Facades\User;

/**
 * Thin helper around Statamic users for lead ownership / assignment.
 */
class UserDirectory
{
    /**
     * Users that may be handed work in the current brand.
     *
     * Two filters, in this order, and both are required:
     *
     * 1. **The permission** — may this account work in LeadHub at all.
     * 2. **The brand membership** — does this person belong to the tenant whose
     *    screen this is.
     *
     * Only the first was asked until v1.9.0, because there was nothing to ask
     * the second with: `statamic-brand-context` isolates Eloquent models, and a
     * Statamic user is not one. So a multi-brand install offered every LeadHub
     * user as an assignee and as a contact owner in every brand — the work
     * stayed isolated, the list of names did not. brand-context 1.5.0 added
     * `brand_user` and `BrandMembers`, which is what the second filter is.
     *
     * **The transition rule stays where it is.** A user with no membership
     * anywhere counts as a member of every brand, and that lives inside
     * `BrandMembers::filter()`. It is deliberately not re-implemented, not
     * pre-filtered and not tightened here: every install upgrading into brand
     * membership starts with an empty table, so a stricter filter written
     * locally would empty every assignee dropdown and every owner select on the
     * day of the upgrade — which reads as a permissions failure, not as a
     * feature. Anything that wants to narrow this list further has to narrow it
     * through BrandMembers, or that migration path breaks without a symptom.
     *
     * **Superusers are not exempt.** `can()` answers true for them, so the
     * permission half never removes a superuser; the brand half does, and it is
     * meant to. Holding every permission is not the same as belonging to a
     * brand, and a superuser assigned to one brand has said which one they work
     * in.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function assignable(): array
    {
        $permitted = User::all()->filter(fn ($user) => $user->can('view leadhub'));

        return BrandMembers::filter($permitted)
            ->map(fn ($user) => [
                'value' => (string) $user->id(),
                'label' => $this->displayName($user),
            ])
            ->sortBy('label')
            ->values()
            ->all();
    }

    public function find(?string $id)
    {
        if ($id === null || $id === '') {
            return null;
        }

        return User::find($id);
    }

    public function label(?string $id): ?string
    {
        $user = $this->find($id);

        return $user ? $this->displayName($user) : null;
    }

    public function email(?string $id): ?string
    {
        $user = $this->find($id);

        return $user?->email();
    }

    protected function displayName($user): string
    {
        $name = method_exists($user, 'name') ? $user->name() : null;

        return $name ?: (string) $user->email();
    }
}
