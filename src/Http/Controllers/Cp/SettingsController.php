<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()?->hasPermission('manage leadhub settings'), 403);

        return view('leadhub::settings', [
            'config' => config('leadhub'),
        ]);
    }
}
