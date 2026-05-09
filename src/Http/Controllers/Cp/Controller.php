<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

abstract class Controller extends BaseController
{
    /**
     * Abort with 403 unless the current user holds $permission.
     *
     * Super users are short-circuited to true. We can't rely on
     * Statamic's User::hasPermission() alone — for File-driver users
     * it does NOT auto-include named permissions when the user is super
     * (super only gets the literal `super` permission appended).
     */
    protected function authorizeOrFail(Request $request, string $permission): void
    {
        $user = $request->user();

        if (! $user) {
            abort(403);
        }

        if (method_exists($user, 'isSuper') && $user->isSuper()) {
            return;
        }

        if (! $user->hasPermission($permission)) {
            abort(403);
        }
    }

    protected function userCan(Request $request, string $permission): bool
    {
        $user = $request->user();
        if (! $user) {
            return false;
        }

        if (method_exists($user, 'isSuper') && $user->isSuper()) {
            return true;
        }

        return $user->hasPermission($permission);
    }
}
