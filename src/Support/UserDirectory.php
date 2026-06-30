<?php

namespace Goldnead\Leadhub\Support;

use Statamic\Facades\User;

/**
 * Thin helper around Statamic users for lead ownership / assignment.
 */
class UserDirectory
{
    /**
     * Users that may own a lead — those who can view LeadHub.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function assignable(): array
    {
        return User::all()
            ->filter(fn ($user) => $user->can('view leadhub'))
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
