<?php

namespace Goldnead\Leadhub\Policies;

use Goldnead\Leadhub\Models\Contact;
use Statamic\Contracts\Auth\User;

class LeadHubPolicy
{
    public function before(?User $user, string $ability): ?bool
    {
        if ($user && method_exists($user, 'isSuper') && $user->isSuper()) {
            return true;
        }

        return null;
    }

    public function viewAny(?User $user): bool
    {
        return $user?->hasPermission('view leadhub contacts') ?? false;
    }

    public function view(?User $user, Contact $contact): bool
    {
        return $user?->hasPermission('view leadhub contacts') ?? false;
    }

    public function create(?User $user): bool
    {
        return $user?->hasPermission('create leadhub contacts') ?? false;
    }

    public function update(?User $user, Contact $contact): bool
    {
        return $user?->hasPermission('edit leadhub contacts') ?? false;
    }

    public function delete(?User $user, Contact $contact): bool
    {
        return $user?->hasPermission('delete leadhub contacts') ?? false;
    }

    public function archive(?User $user, Contact $contact): bool
    {
        return $user?->hasPermission('archive leadhub contacts') ?? false;
    }

    public function export(?User $user): bool
    {
        return $user?->hasPermission('export leadhub contacts') ?? false;
    }

    public function manageTags(?User $user): bool
    {
        return $user?->hasPermission('manage leadhub tags') ?? false;
    }

    public function manageFormMappings(?User $user): bool
    {
        return $user?->hasPermission('manage leadhub form mappings') ?? false;
    }

    public function manageSettings(?User $user): bool
    {
        return $user?->hasPermission('manage leadhub settings') ?? false;
    }
}
