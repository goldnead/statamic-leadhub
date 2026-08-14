<?php

namespace Goldnead\Leadhub\Http\Controllers\Cp;

use Goldnead\Leadhub\Http\Requests\UpdateSettingsRequest;
use Goldnead\Leadhub\Support\Settings;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SettingsController extends Controller
{
    /**
     * The settings screen: a form, plus the parts that are not settings.
     *
     * The form is drawn from {@see Settings::groups()} — the same definition the
     * validation and the boot-time config override read — so a field cannot
     * exist on screen without a rule behind it, or the other way round.
     *
     * The `config` prop is an explicit allow-list, not `config('leadhub')`.
     * Handing the whole config file to Inertia serialized `crm.destinations.*`
     * into the page — those entries hold `token`, `api_key` and `secret`, so
     * every install with a CRM connector shipped its credentials into the DOM,
     * devtools and the browser cache. It stays exactly as narrow as it was; the
     * editable form gets its values from `values`, which only ever holds the
     * keys `Settings` offers and no credential is among them. Pinned by
     * tests/Feature/SettingsSecretsTest.php.
     */
    public function index(Request $request, Settings $settings)
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

            'groups' => Settings::groups(),
            'values' => $this->current($settings),
            'updateUrl' => cp_route('leadhub.settings.update'),
            // The page itself is gated on the `manage leadhub settings`
            // permission a few lines above, so anybody who gets this far may
            // save — provided there is somewhere to save to. On a flat-driver
            // install that never ran a migration there is not, and the form says
            // so instead of offering a Save button that answers a SQL error.
            'canEdit' => $settings->isWritable(),
            'environment' => $this->environmentPayload(),
            // Page chrome, translated here rather than in the component. The
            // rest of the screen's text already comes from `leadhub::settings`
            // (the form is generated from a definition that carries lang keys),
            // and splitting the same file across two translation layers — PHP
            // for the fields, JSON strings for the headings around them — is how
            // a screen ends up half German.
            'texts' => $this->texts(),
        ]);
    }

    /**
     * Write the settings form.
     *
     * Redirects back rather than answering JSON, because the page mutates
     * through Inertia's `router` like every other screen in this addon: the
     * redirect re-renders `values` from the installation as it now stands, which
     * is not always what was typed. An integer arrives from a text input as a
     * string and comes back an integer, a list arrives with the blank line the
     * textarea left behind and comes back compacted, and a value equal to the
     * default is dropped and comes back as whatever the config file says.
     * Echoing the request instead would leave the screen agreeing with itself
     * and disagreeing with the installation.
     */
    public function update(UpdateSettingsRequest $request, Settings $settings)
    {
        if (! $settings->isWritable()) {
            return back()->with('error', __('leadhub::settings.unavailable'));
        }

        $settings->save($this->normalise($request->validated()['settings']));

        return back()->with('success', __('leadhub::settings.saved'));
    }

    /**
     * The strings around the form.
     *
     * @return array<string, string>
     */
    protected function texts(): array
    {
        return [
            'intro' => __('leadhub::settings.intro', ['path' => config_path('leadhub.php')]),
            'save' => __('leadhub::settings.save'),
            'saving' => __('leadhub::settings.saving'),
            'saved' => __('leadhub::settings.saved'),
            'unavailable' => __('leadhub::settings.unavailable'),
            'defaultPlaceholder' => __('leadhub::settings.default_placeholder'),
            'statusesHeading' => __('leadhub::settings.statuses.heading'),
            'statusesDescription' => __('leadhub::settings.statuses.description'),
            'environmentHeading' => __('leadhub::settings.environment.heading'),
            'environmentDescription' => __('leadhub::settings.environment.description'),
        ];
    }

    /**
     * Coerce the form's values into the types the config expects.
     *
     * HTML form controls hand back strings; `config('leadhub.exports.queue_threshold')`
     * is compared against a contact count and a `"1000"` there is a bug waiting
     * for a strict comparison — including the one that decides whether a value
     * is back at its default and the row may go.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function normalise(array $values): array
    {
        $fields = Settings::fields();
        $out = [];

        foreach ($values as $key => $value) {
            $field = $fields[$key] ?? null;

            if ($field === null) {
                continue;
            }

            if (($field['nullable'] ?? false) && ($value === '' || $value === null)) {
                $out[$key] = null;

                continue;
            }

            $out[$key] = match ($field['type']) {
                'boolean' => (bool) $value,
                'integer' => (int) $value,
                // A list control is a textarea of lines, so blanks and stray
                // whitespace are normal input, not errors: a trailing newline
                // must not become a redaction rule for the empty field name.
                'list' => array_values(array_filter(
                    array_map(fn ($item) => trim((string) $item), (array) $value),
                    fn (string $item) => $item !== '',
                )),
                default => (string) $value,
            };
        }

        return $out;
    }

    /**
     * The current value of every editable setting, keyed by dotted path.
     *
     * Flat, not nested: the form indexes by the same path the definition uses,
     * so the screen never has to know how the config file happens to be shaped.
     *
     * @return array<string, mixed>
     */
    protected function current(Settings $settings): array
    {
        $values = [];

        foreach (array_keys(Settings::fields()) as $key) {
            $values[$key] = $settings->value($key);
        }

        return $values;
    }

    /**
     * Settings the deployment owns, shown but never editable.
     *
     * All of these resolve from `env()`. A database row that outranks an env var
     * is a setting that changes back on the next deploy without anyone touching
     * the screen. `storage.driver` is worse than that: it decides where
     * contacts, events and notes live, and switching it under a running install
     * has to move them first — that is what `leadhub:storage:migrate` is for, and
     * a second, silent way to flip the same switch would strand the data in the
     * old store.
     *
     * No credential is reported by value. The CRM destinations are not here at
     * all: their `token`, `api_key` and `secret` are the reason this screen has
     * an allow-list in the first place.
     *
     * @return array<int, array{label: string, value: string, env: string}>
     */
    protected function environmentPayload(): array
    {
        $notifications = (array) config('leadhub.notifications', []);
        $digest = (array) ($notifications['digest'] ?? []);

        $none = __('leadhub::settings.environment.none');
        $yesNo = fn (mixed $value) => $value
            ? __('leadhub::settings.environment.on')
            : __('leadhub::settings.environment.off');

        return [
            [
                'label' => __('leadhub::settings.environment.storage_driver'),
                'value' => (string) config('leadhub.storage.driver', 'eloquent'),
                'env' => 'LEADHUB_DRIVER',
            ],
            [
                'label' => __('leadhub::settings.environment.flat_path'),
                'value' => (string) config('leadhub.storage.flat.path', ''),
                'env' => 'LEADHUB_FLAT_PATH',
            ],
            [
                'label' => __('leadhub::settings.environment.notifications'),
                'value' => $yesNo($notifications['enabled'] ?? false),
                'env' => 'LEADHUB_NOTIFICATIONS',
            ],
            [
                'label' => __('leadhub::settings.environment.notify_recipients'),
                'value' => implode(', ', (array) ($notifications['recipients'] ?? [])) ?: $none,
                'env' => 'LEADHUB_NOTIFY_EMAILS',
            ],
            [
                'label' => __('leadhub::settings.environment.digest_time'),
                'value' => (string) ($digest['time'] ?? '08:00'),
                'env' => 'LEADHUB_DIGEST_TIME',
            ],
            [
                'label' => __('leadhub::settings.environment.digest_recipients'),
                'value' => implode(', ', (array) ($digest['fallback_recipients'] ?? [])) ?: $none,
                'env' => 'LEADHUB_DIGEST_EMAILS',
            ],
        ];
    }
}
