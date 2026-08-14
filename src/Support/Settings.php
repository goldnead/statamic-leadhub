<?php

namespace Goldnead\Leadhub\Support;

use Goldnead\Leadhub\Models\Setting;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The settings an operator may change from the Control Panel, and the one place
 * that knows what those are.
 *
 * Three readers share this definition — the boot-time override, the validation
 * on the way in, and the screen that draws the form — so a setting is added by
 * adding one entry to {@see groups()} and nothing else falls out of step. The
 * screen in particular is generated from here rather than from a hand-kept list
 * of labels in JavaScript, which is what the read-only version was: a second
 * description of `config/leadhub.php` that could disagree with it and never say
 * so.
 *
 * **Overrides, not a copy.** Only keys somebody actually changed are stored.
 * Everything else keeps following `config/leadhub.php`, so upgrading the package
 * still moves the defaults, and a site that never opens this screen is
 * indistinguishable from one running a release before the screen existed.
 *
 * **What is not here, and why.**
 *
 * - `statuses`. It is a map of handle => label, not a value: editing it means
 *   adding, renaming and removing rows, and removing one strands every contact
 *   sitting on it (the config file says as much). A textarea that round-trips a
 *   map through some invented separator is a worse editor than none, so the
 *   screen keeps printing the statuses read-only and `default_status` is offered
 *   as a select over them.
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
 *   every backup, and this screen already refuses to serialize them to the
 *   browser at all — see SettingsController and tests/Feature/SettingsSecretsTest.
 * - Everything resolved from `env()`: `storage.driver`, `storage.flat.*`,
 *   `notifications.enabled`, `notifications.recipients`,
 *   `notifications.digest.time`, `notifications.digest.fallback_recipients`.
 *   These belong to the deployment; a database row that silently outranks an env
 *   var is a setting that changes back on the next deploy with nobody touching
 *   the screen. `storage.driver` is worse still: it decides where contacts,
 *   events and notes live, and switching it under a running install has to move
 *   them first (`php artisan leadhub:storage:migrate`). They are shown on the
 *   screen, read-only, so an operator can check them.
 * - `email_normalization.*`. Not a preference but a data-consistency rule: the
 *   normalized address is written into `leadhub_contacts.email_normalized` and
 *   carries a unique index with `brand_id`. Changing the rule afterwards leaves
 *   every existing row normalized by the old one, so deduplication silently
 *   stops matching the contacts it used to match.
 */
class Settings
{
    /** Cache key for the stored overrides. */
    public const CACHE_KEY = 'leadhub.settings.overrides';

    public function __construct(protected Application $app) {}

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
    public static function groups(): array
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

    /**
     * Every editable field, flattened.
     *
     * @return array<string, array<string, mixed>> key => field
     */
    public static function fields(): array
    {
        $fields = [];

        foreach (static::groups() as $group) {
            foreach ($group['fields'] as $field) {
                $fields[$field['key']] = $field;
            }
        }

        return $fields;
    }

    /**
     * The stored overrides, keyed by dotted path.
     *
     * Cached because this is read on every boot, including the boot of every
     * queue worker that runs an export or a digest. A missing table (installed
     * but not migrated, or running the flat driver where migrations are not
     * required) or an unreachable cache is not fatal: no overrides means the
     * config file, which is exactly the behaviour before this screen existed.
     *
     * @return array<string, mixed>
     */
    public function overrides(): array
    {
        try {
            return Cache::rememberForever(self::CACHE_KEY, fn () => $this->read());
        } catch (\Throwable) {
            try {
                return $this->read();
            } catch (\Throwable) {
                return [];
            }
        }
    }

    /**
     * Whether this install can store an override at all.
     *
     * The flat driver keeps lead data in YAML and does not require migrations,
     * so the one table this screen needs may genuinely not be there. Reading
     * already survives that — {@see overrides()} answers "no overrides", which
     * is the config file, which is right. Writing does not: without this the
     * Save button would answer a SQL error. The screen asks first and says so.
     */
    public function isWritable(): bool
    {
        try {
            return Schema::hasTable((new Setting)->getTable());
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    protected function read(): array
    {
        return Setting::query()
            ->pluck('value', 'key')
            ->all();
    }

    /**
     * Push the stored overrides onto the live config.
     *
     * Overriding the config rather than teaching every reader about this class
     * is the whole point: `config('leadhub.features.tasks')` is read from the
     * navigation, the controllers, the listeners and the console commands, and a
     * second source of truth next to it would be one missed call site away from
     * a setting that looks changed and is not.
     */
    public function apply(): void
    {
        // `config:cache` boots the app fully and then dumps the whole resolved
        // config to `bootstrap/cache/config.php`. Applying here during that
        // build would bake the overrides into the cached file, and a baked
        // override outlives the row it came from: deleting a setting would then
        // have no effect at all until somebody ran `config:clear`.
        //
        // It would also poison the baseline below. On the next boot the config
        // is cached, so `config('leadhub')` *is* the baked file — the snapshot
        // would record the override as the packaged default, and a value reset
        // to the file's default would be stored as a row instead of deleted,
        // which is precisely the property this class promises.
        //
        // Skipping is safe: the cached config keeps the file values, and every
        // process that reads it applies the overrides on its own boot.
        if (method_exists($this->app, 'runningConsoleCommand') && $this->app->runningConsoleCommand('config:cache')) {
            return;
        }

        // Snapshot what the config *files* say, before anything stored covers
        // it. This is what "back to default" means on this install: the package
        // config as the host published and edited it, not the copy inside the
        // package — a site that changed a default in its own `config/leadhub.php`
        // must be able to return to that value.
        $this->baseline ??= (array) config('leadhub', []);

        $overrides = $this->overrides();

        // The common case is an install that never opened the screen, and this
        // runs on every boot — including every queue worker's. Nothing stored,
        // nothing to do, and in particular no reason to build the field
        // definition, which translates every label in it.
        if ($overrides === []) {
            return;
        }

        $fields = static::fields();

        foreach ($overrides as $key => $value) {
            // Only keys this class offers. A row left behind by an older release
            // must not be able to set an arbitrary config path — `storage.driver`
            // and the CRM credentials are one string away otherwise.
            if (! isset($fields[$key])) {
                continue;
            }

            config()->set('leadhub.'.$key, $value);
        }
    }

    /**
     * The value on screen for one field: the override if there is one, else
     * whatever the config file says.
     */
    public function value(string $key): mixed
    {
        return config('leadhub.'.$key);
    }

    /**
     * Write the changed settings.
     *
     * A value equal to the packaged default is *deleted* rather than stored, so
     * "back to default" is reachable from the form and the table does not
     * accumulate rows that pin a value to what it already was.
     *
     * @param  array<string, mixed>  $values  key => value, keys from {@see fields()}
     */
    public function save(array $values): void
    {
        $fields = static::fields();

        // All of it or none of it. A failure halfway through leaves a settings
        // table matching no coherent state, and the screen is then re-rendered
        // from that half-written state as though it were the truth.
        DB::transaction(function () use ($values, $fields): void {
            $this->write($values, $fields);
        });

        $this->forget();
        $this->apply();
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $fields
     */
    protected function write(array $values, array $fields): void
    {
        foreach ($values as $key => $value) {
            if (! isset($fields[$key])) {
                continue;
            }

            if ($value === $this->packagedDefault($key)) {
                Setting::query()->where('key', $key)->delete();

                // Put the file's value back on the live config by hand.
                // `apply()` below only writes the overrides that exist, so a
                // deleted one would leave the old value standing in this
                // process — the row gone, the screen saying "default", and
                // everything reading `config()` until the next boot still
                // getting the value that was just taken away.
                config()->set('leadhub.'.$key, $value);

                continue;
            }

            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
    }

    /** Drop the cached overrides so the next read sees the table. */
    public function forget(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (\Throwable) {
            // No cache store is a running-without-cache install, not a failure
            // to save: `read()` then hits the table on every call anyway.
        }
    }

    /**
     * The config as the files have it, taken before any override was applied.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $baseline = null;

    /**
     * What the config files say, ignoring any override already applied to the
     * live config.
     *
     * Never `config()`: by the time anything asks, {@see apply()} has already
     * overwritten the live value with the override, so comparing against it
     * would report every stored value as equal to the default and delete the lot
     * on the next save. The package file is the last resort, for a call that
     * happens before `apply()` ever ran.
     */
    protected function packagedDefault(string $key): mixed
    {
        if ($this->baseline === null) {
            $this->baseline = (array) require __DIR__.'/../../config/leadhub.php';
        }

        return data_get($this->baseline, $key);
    }
}
