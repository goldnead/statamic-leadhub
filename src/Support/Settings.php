<?php

namespace Goldnead\Leadhub\Support;

use Goldnead\BrandContext\Contracts\ProvidesSettings;

/**
 * The settings an operator may change from the Control Panel, and the one place
 * that knows what those are.
 *
 * Since 1.x this class is **only** the field list. The store, the screen, the
 * validation, the routes and the brand dimension all come from
 * `goldnead/statamic-brand-context` — see {@see ProvidesSettings}, which this
 * implements. What used to live here as well (a key/value model on
 * `leadhub_settings`, a form request, a controller, a Vue page and a boot-time
 * config override) was the third independent copy of one mechanism in this
 * addon family, and the only part of it that legitimately belonged to LeadHub
 * was {@see settingsGroups()}.
 *
 * Three readers share that definition — the config override, the validation on
 * the way in, and the screen that draws the form — so a setting is added by
 * adding one entry and nothing else falls out of step.
 *
 * **Overrides, not a copy.** Only keys somebody actually changed are stored.
 * Everything else keeps following `config/leadhub.php`, so upgrading the package
 * still moves the defaults, and a site that never opens this screen is
 * indistinguishable from one running a release before the screen existed.
 *
 * **Per brand, not per installation.** That is the one behavioural change of
 * the move. `leadhub_settings` had no brand column, so on a multi-brand install
 * both brands read one row; `brand_settings` carries `brand_id` and the screen
 * always writes the brand in the switcher. The migration puts every existing
 * row on the default brand, which is the only reading of an un-branded row that
 * cannot invent a value for a brand that never had one.
 *
 * **What is not here, and why.**
 *
 * - `statuses`. It is a map of handle => label, not a value: editing it means
 *   adding, renaming and removing rows, and removing one strands every contact
 *   sitting on it (the config file says as much). A textarea that round-trips a
 *   map through some invented separator is a worse editor than none, so
 *   `default_status` is offered as a select over them and the list itself is
 *   printed read-only on the LeadHub dashboard.
 * - `attribution.fields` is the same shape for the same reason: a map from
 *   contact column to submission handle, where the left-hand side is a database
 *   column and a typo silently stops capturing UTM data.
 * - `scoring.events`. Two reasons, either of which is enough. Since v1.8.0 it is
 *   only the fallback for a brand that has no rows in `leadhub_scoring_rules` —
 *   the live table is edited under LeadHub → Scoring, per brand, so a number
 *   changed here would look effective and do nothing on every brand that has
 *   rules. And two of its keys carry literal dots (`purchase.completed`,
 *   `booking.confirmed`), which the dotted-path addressing this whole mechanism
 *   is built on cannot express: `config()->set()` would create a nested array
 *   instead of writing the flat key. `scoring.default` and `scoring.timeline`
 *   are both read live and are here.
 * - `crm.destinations`. Credentials (`token`, `api_key`, `secret`), env-backed.
 *   A database row holding one would take it out of the secret store and into
 *   every backup, and no Control Panel screen of this addon serializes them to
 *   the browser — see DashboardController::environmentPayload() and
 *   tests/Feature/SettingsSecretsTest.
 * - Everything resolved from `env()`: `storage.driver`, `storage.flat.*`,
 *   `notifications.enabled`, `notifications.recipients`,
 *   `notifications.digest.time`, `notifications.digest.fallback_recipients`.
 *   These belong to the deployment; a database row that silently outranks an env
 *   var is a setting that changes back on the next deploy with nobody touching
 *   the screen. `storage.driver` is worse still: it decides where contacts,
 *   events and notes live, and switching it under a running install has to move
 *   them first (`php artisan leadhub:storage:migrate`). They are printed
 *   read-only on the LeadHub dashboard, so an operator can check them.
 * - `email_normalization.*`. Not a preference but a data-consistency rule: the
 *   normalized address is written into `leadhub_contacts.email_normalized` and
 *   carries a unique index with `brand_id`. Changing the rule afterwards leaves
 *   every existing row normalized by the old one, so deduplication silently
 *   stops matching the contacts it used to match.
 */
class Settings implements ProvidesSettings
{
    /**
     * Stable forever: it is stored in `brand_settings.namespace` on every row,
     * so renaming it orphans every override a site has made.
     */
    public static function settingsNamespace(): string
    {
        return 'leadhub';
    }

    /**
     * The config root the unset values keep following — `config/leadhub.php`,
     * which is what every `config('leadhub.…')` call in this addon reads.
     */
    public static function settingsConfigPath(): string
    {
        return 'leadhub';
    }

    /**
     * The permission that gates this addon's section.
     *
     * The literal string the service provider registers, not a name derived
     * from the namespace. It is already assigned to user groups on installed
     * sites, and it also gates the custom fields and the pipeline configuration
     * screens, so a derived name would take three screens away from everyone
     * who has it without anything on screen saying why.
     */
    public static function settingsPermission(): string
    {
        return 'manage leadhub settings';
    }

    /**
     * The editable settings, in the order and grouping the screen shows them.
     *
     * `key` is the path under `leadhub.`. `type` drives both the control on
     * screen and the validation rule. `nullable` means empty is a real value —
     * no field currently needs it, but every reader honours it, so a nullable
     * setting can be added without touching three files.
     *
     * @return array<int, array{title: string, description: string, fields: array<int, array<string, mixed>>}>
     */
    public static function settingsGroups(): array
    {
        return [
            [
                'title' => __('leadhub::settings.groups.behavior.title'),
                'description' => __('leadhub::settings.groups.behavior.description'),
                'fields' => [
                    static::field('default_status', 'select', ['options' => static::statusOptions()]),
                    static::field('overwrite_existing_fields_from_submissions', 'boolean'),
                    static::field('store_full_submission_payload', 'boolean'),
                ],
            ],
            [
                'title' => __('leadhub::settings.groups.redaction.title'),
                'description' => __('leadhub::settings.groups.redaction.description'),
                'fields' => [
                    static::field('timeline_payload_redaction', 'list'),
                ],
            ],
            [
                'title' => __('leadhub::settings.groups.features.title'),
                'description' => __('leadhub::settings.groups.features.description'),
                'fields' => [
                    static::field('features.manual_contacts', 'boolean'),
                    static::field('features.csv_export', 'boolean'),
                    static::field('features.attribution', 'boolean'),
                    static::field('features.ingestion', 'boolean'),
                    static::field('features.merge', 'boolean'),
                    static::field('features.companies', 'boolean'),
                    static::field('features.tasks', 'boolean'),
                    static::field('features.pipelines', 'boolean'),
                    static::field('features.scoring', 'boolean'),
                    static::field('features.webhooks', 'boolean'),
                    static::field('features.crm_destinations', 'boolean'),
                    static::field('features.webhook_manager', 'boolean'),
                    static::field('features.click_tracking', 'boolean'),
                ],
            ],
            [
                'title' => __('leadhub::settings.groups.exports.title'),
                'description' => __('leadhub::settings.groups.exports.description'),
                'fields' => [
                    static::field('exports.queue_threshold', 'integer', ['min' => 1]),
                    static::field('exports.disk', 'select', ['options' => static::diskOptions()]),
                    static::field('exports.directory', 'string'),
                ],
            ],
            [
                'title' => __('leadhub::settings.groups.scoring.title'),
                'description' => __('leadhub::settings.groups.scoring.description'),
                'fields' => [
                    // No floor: a negative catch-all is a legitimate way to make
                    // unlisted activity cost points rather than earn them, and
                    // the scoring rules table allows the same range.
                    static::field('scoring.default', 'integer'),
                    static::field('scoring.timeline', 'boolean'),
                ],
            ],
            [
                'title' => __('leadhub::settings.groups.click_tracking.title'),
                'description' => __('leadhub::settings.groups.click_tracking.description'),
                'fields' => [
                    // 0 is "score every click" and has to stay reachable, so
                    // this integer floors at zero on purpose.
                    static::field('click_tracking.dedupe_window', 'integer', ['min' => 0]),
                    static::field('click_tracking.ignored_query_parameters', 'list'),
                ],
            ],
            [
                'title' => __('leadhub::settings.groups.notifications.title'),
                'description' => __('leadhub::settings.groups.notifications.description'),
                'fields' => [
                    static::field('notifications.new_lead', 'boolean'),
                    static::field('notifications.on_assignment', 'boolean'),
                    static::field('notifications.on_task_assignment', 'boolean'),
                    static::field('notifications.digest.enabled', 'boolean'),
                ],
            ],
        ];
    }

    /**
     * One field, with its label and description taken from the lang files.
     *
     * The translation key is the config path with the dots flattened, because a
     * dot inside a lang key is a path separator to the translator and
     * `settings.fields.exports.disk.label` would be looked up as four nested
     * arrays that do not exist.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected static function field(string $key, string $type, array $extra = []): array
    {
        $handle = str_replace('.', '_', $key);

        return array_merge([
            'key' => $key,
            'type' => $type,
            'label' => __("leadhub::settings.fields.{$handle}.label"),
            'description' => __("leadhub::settings.fields.{$handle}.description"),
            'nullable' => false,
        ], $extra);
    }

    /**
     * The statuses a new contact may land on.
     *
     * Taken from `leadhub.statuses`, which is the map the Control Panel renders
     * everywhere else — and unioned with whatever `default_status` is right now,
     * so an install whose default was never listed there can still save this
     * form. Without the union the current value would fail its own `in:` rule
     * and every other field on the page would be unsaveable with it.
     *
     * @return array<int, array{value: string, label: string}>
     */
    protected static function statusOptions(): array
    {
        $statuses = (array) config('leadhub.statuses', []);
        $current = (string) config('leadhub.default_status', '');

        if ($current !== '' && ! array_key_exists($current, $statuses)) {
            $statuses[$current] = $current;
        }

        $options = [];

        foreach ($statuses as $value => $label) {
            $options[] = ['value' => (string) $value, 'label' => (string) $label];
        }

        return $options;
    }

    /**
     * The filesystem disks an export may be written to.
     *
     * A free-text disk name is a typo away from an export that fails at the
     * moment somebody presses Download, far from this screen. Unioned with the
     * configured value for the same reason as the statuses above.
     *
     * @return array<int, array{value: string, label: string}>
     */
    protected static function diskOptions(): array
    {
        $disks = array_keys((array) config('filesystems.disks', []));
        $disks[] = (string) config('leadhub.exports.disk', 'local');

        $disks = array_values(array_unique(array_filter($disks, fn ($disk) => $disk !== '')));

        return array_map(fn ($disk) => ['value' => (string) $disk, 'label' => (string) $disk], $disks);
    }

    // Everything that used to follow — `fields()`, `overrides()`,
    // `isWritable()`, `read()`, `apply()`, `value()`, `save()`, `write()`,
    // `forget()`, the baseline snapshot and `packagedDefault()` — is now
    // Goldnead\BrandContext\Settings\{SettingsRegistry,SettingsManager,NamespaceSettings}.
    // The registry answers `fields('leadhub')` from the definition above; the
    // manager owns the cache, the baseline and the config override. Keeping a
    // second reader here is exactly the duplication this move removed.

}
