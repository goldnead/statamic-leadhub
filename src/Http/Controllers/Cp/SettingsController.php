<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Illuminate\Http\Request;
use Inertia\Inertia;

class SettingsController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()?->hasPermission('manage leadhub settings'), 403);

        return Inertia::render('leadhub::Settings', [
            'config' => config('leadhub'),
            'driver' => config('leadhub.storage.driver', 'eloquent'),
            'publishCommand' => 'php artisan vendor:publish --tag=leadhub-config',
        ]);
    }
}
