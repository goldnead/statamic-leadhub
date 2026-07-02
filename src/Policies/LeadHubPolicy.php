<?php

namespace Goldnead\Leadhub\Policies;

use Goldnead\Leadhub\Models\Contact;
use Illuminate\Contracts\Auth\Authenticatable as User;
use Statamic\Facades\User as StatamicUser;

class LeadHubPolicy
{
    /**
     * The Gate passes the raw auth user — on eloquent-driver sites that is
     * a plain App\Models\User, so we resolve the Statamic user through
     * User::fromUser() before asking Statamic-specific questions. The
     * per-ability checks below use $user->can(), which Statamic wires into
     * the Gate for both user drivers.
     */
    public function before(?User $user, string $ability): ?bool
    {
        if ($user && StatamicUser::fromUser($user)?->isSuper()) {
            return true;
        }

        return null;
    }

    public function viewAny(?User $user): bool
    {
        return $user?->can('view leadhub contacts') ?? false;
    }

    public function view(?User $user, Contact $contact): bool
    {
        return $user?->can('view leadhub contacts') ?? false;
    }

    public function create(?User $user): bool
    {
        return $user?->can('create leadhub contacts') ?? false;
    }

    public function update(?User $user, Contact $contact): bool
    {
        return $user?->can('edit leadhub contacts') ?? false;
    }

    public function delete(?User $user, Contact $contact): bool
    {
        return $user?->can('delete leadhub contacts') ?? false;
    }

    public function archive(?User $user, Contact $contact): bool
    {
        return $user?->can('archive leadhub contacts') ?? false;
    }

    public function export(?User $user): bool
    {
        return $user?->can('export leadhub contacts') ?? false;
    }

    public function manageTags(?User $user): bool
    {
        return $user?->can('manage leadhub tags') ?? false;
    }

    public function manageFormMappings(?User $user): bool
    {
        return $user?->can('manage leadhub form mappings') ?? false;
    }

    public function manageSettings(?User $user): bool
    {
        return $user?->can('manage leadhub settings') ?? false;
    }
}
