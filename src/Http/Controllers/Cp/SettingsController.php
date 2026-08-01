<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Illuminate\Http\Request;
use Inertia\Inertia;

class SettingsController extends Controller
{
    /**
     * The read-only settings screen.
     *
     * The `config` prop is an explicit allow-list, not `config('leadhub')`.
     * Handing the whole config file to Inertia serialized `crm.destinations.*`
     * into the page — those entries hold `token`, `api_key` and `secret`, so
     * every install with a CRM connector shipped its credentials into the DOM,
     * devtools and the browser cache. The screen renders statuses, four
     * behaviour values, the redaction list and the feature flags; it gets
     * exactly those. Pinned by tests/Feature/SettingsSecretsTest.php.
     */
    public function index(Request $request)
    {
        $this->authorizeOrFail($request, 'manage leadhub settings');

        return Inertia::render('leadhub::Settings', [
            'config' => [
                'statuses' => config('leadhub.statuses', []),
                'default_status' => config('leadhub.default_status'),
                'overwrite_existing_fields_from_submissions' => (bool) config('leadhub.overwrite_existing_fields_from_submissions', false),
                'store_full_submission_payload' => (bool) config('leadhub.store_full_submission_payload', false),
                'timeline_payload_redaction' => config('leadhub.timeline_payload_redaction', []),
                'features' => config('leadhub.features', []),
                'exports' => [
                    'queue_threshold' => config('leadhub.exports.queue_threshold'),
                ],
            ],
            'driver' => config('leadhub.storage.driver', 'eloquent'),
            'publishCommand' => 'php artisan vendor:publish --tag=leadhub-config',
        ]);
    }
}
