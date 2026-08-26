<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

abstract class Controller extends BaseController
{
    /**
     * Abort with 403 unless the current user holds $permission.
     *
     * Permission checks go through Laravel's Gate ($user->can()) instead of
     * Statamic's User::hasPermission(): Statamic registers a Gate::after
     * hook that resolves the Statamic user via User::fromUser() and
     * short-circuits super users, so can() is correct for BOTH the file
     * and the eloquent users repository. Calling hasPermission()/isSuper()
     * on the raw auth user crashes on eloquent-driver sites where the
     * authenticated model is a plain App\Models\User.
     */
    /**
     * A screen backed by a table is a screen the flat-file driver cannot serve.
     *
     * On the base class rather than copied into each controller, because that
     * is how it went wrong: CustomFieldController called this method four
     * times, the copy lived privately in ScoringController, and every one of
     * its actions answered 500. 566 tests stayed green, because none of them
     * asked for the route.
     */
    protected function abortUnlessEloquent(): void
    {
        abort_unless(config('leadhub.storage.driver', 'eloquent') === 'eloquent', 404);
    }

    protected function authorizeOrFail(Request $request, string $permission): void
    {
        if (! $this->userCan($request, $permission)) {
            abort(403);
        }
    }

    protected function userCan(Request $request, string $permission): bool
    {
        return (bool) $request->user()?->can($permission);
    }

    /**
     * The authenticated user's identifier as a string ('' when guest).
     *
     * Uses getAuthIdentifier() from Laravel's Authenticatable contract —
     * Statamic file users and plain eloquent users both implement it,
     * whereas id() only exists on Statamic's user classes.
     */
    protected function userId(Request $request): string
    {
        return (string) ($request->user()?->getAuthIdentifier() ?? '');
    }
}
