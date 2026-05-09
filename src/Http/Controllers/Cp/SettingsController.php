<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Illuminate\Http\Request;
use Inertia\Inertia;

class SettingsController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeOrFail($request, 'manage leadhub settings');

        return Inertia::render('leadhub::Settings', [
            'config' => config('leadhub'),
            'driver' => config('leadhub.storage.driver', 'eloquent'),
            'publishCommand' => 'php artisan vendor:publish --tag=leadhub-config',
        ]);
    }
}
