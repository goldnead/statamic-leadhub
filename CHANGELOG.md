# Changelog

All notable changes to `goldnead/statamic-leadhub` are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.0] — 2026-06-30

First stable release. The addon now installs and runs cleanly on a current Statamic 6 project out of the box.

### Fixed

- **Installation failed on a fresh Statamic 6 project.** The default Statamic 6 skeleton now ships Laravel 13, but the framework constraint capped at `^11.0|^12.0`, so `composer require` could not resolve. Widened to `^11.0|^12.0|^13.0` (and `orchestra/testbench` to allow `^11.0` for the dev suite). Verified resolving against `laravel/framework v13.17` + `statamic/cms v6.23`.
- **Every Control Panel page returned HTTP 500 (`Vite manifest not found`).** The compiled CP assets were never shipped — `public/build` was gitignored and there is no mechanism by which the host project's `npm run build` compiles an addon's entries. Adopted the official Statamic 6 addon Vite convention (`@statamic/cms/vite-plugin`, output to `resources/dist/`) and now **ship the compiled assets in the package**, which Statamic publishes to the host's `public/vendor/` on install. No end-user build step is required.

### Added

- `scripts/setup-playground.sh` — builds a persistent, runnable Statamic 6 playground with the addon wired in as a path repository, for local CP testing and development.

### Changed

- Installation is now just `composer require` + `php artisan migrate` — the `npm run build` step (and the inaccurate "host Vite auto-picks the addon's entries" claim) has been removed from the docs.
- Author contact details updated.

## [0.3.1] — 2026-05-09

Two more real CP bugs caught by extending `CpRoutesTest` to actually run against both drivers (PHPUnit Sandbox skill). Both surfaced as 404s on the contact-detail page in flat mode.

### Fixed

- **Eloquent's int cast on the primary key destroyed UUIDs in flat-file mode.** When `FlatFileContactRepository::create()` writes `id = uuid` (string), the Contact model's default `$incrementing = true` meant Eloquent applied an int cast on `$contact->id`, so PHP truncated `"d9e2a599-…"` to `0`. URLs were then built as `/cp/leadhub/contacts/0`, the flat-file index had no key `"0"`, and the controller returned 404. Fixed by switching all controller-built URLs and prop ids to `$contact->uuid` (set in both drivers via `Model::booted()`) and making the eloquent repository's `find($id)` accept either an int id or a UUID string.
- **`CpRoutesTest::it renders the contact detail page` failed against the flat driver** because the test created the contact via the eloquent factory (which writes to the in-memory SQLite) while the active driver looked in YAML. Switched to `app(ContactRepository::class)->create(...)` so the test creates through whichever driver the matrix is exercising.

### Added

- UUID-aware `find()` in `EloquentContactRepository`, `EloquentTagRepository`, and `EloquentFollowupRepository`. Routes now use the UUID (string) consistently across both drivers — the int auto-increment id is still in the DB but is no longer the address.

## [0.3.1-pre] — 2026-05-08

End-to-end CP HTTP smoke-tested for the first time. Four real production bugs found and fixed — none of which the domain-layer Pest suite would have caught.

### Fixed

- **Super users couldn't access any LeadHub CP page.** Statamic 6.18's `Auth\File\User::hasPermission()` only checks the user's permissions collection — and for super users that collection contains the literal `'super'` permission, NOT the per-feature permissions. So `$user->hasPermission('view leadhub')` returned `false` for any admin (you!), causing 403 on every LeadHub page. v0.3.1 adds a super-status short-circuit in the controller base class. ([`Cp\Controller::authorizeOrFail`])
- **`Column::sortable()` without an argument is a getter (returns `bool`)**, not a fluent setter — chaining `->sortable()->...` broke the column array build. Fixed in `ContactController`, `FormMappingController`, `TagController` by passing `sortable(true)` explicitly.
- **`Eloquent::getRelation()` throws "Undefined array key" when the relation isn't loaded**, instead of returning null. Replaced unsafe `getRelation('foo') ?? collect()` with `relationLoaded('foo') ? getRelation('foo') : collect()` across `ContactController` and `ExportService`.
- **CP routes weren't being mounted in the test environment** — Statamic registers them inside `Statamic::booted` callbacks that orchestra/testbench doesn't fire. `tests/TestCase::defineRoutes()` now mounts the addon routes manually under the `statamic.cp.` name prefix, matching production.

### Added

- **`tests/Feature/CpRoutesTest`** — 9 HTTP smoke tests that hit each CP page as an authenticated super user and assert: HTTP 200, Inertia headers correct, component identifier matches the registered Vue page. This is the test class that would have caught all four v0.3.0 bugs.
- **Explicit `bootAddon()` call in `TestCase::setUp`** — Statamic's `Statamic::booted` callbacks don't fire under testbench, so without this the navigation, permissions, and route registration silently no-op in tests.
- `Cp\Controller::authorizeOrFail($request, $permission)` and `Cp\Controller::userCan(...)` helpers — single source of truth for permission gates with super-user bypass.

### Notes

This is the first release verified by the *Statamic 6 PHPUnit Sandbox* skill — full PHP + Composer + PHPUnit run in the sandbox, including HTTP feature tests against real routes. **63/63 tests pass.** Highly recommend running `composer install && vendor/bin/pest` if you fork the addon — the suite is now fast (~2.5s) and catches real CP regressions.

## [0.3.0] — 2026-05-08

### Statamic 6-native CP rewrite

The Control Panel is now built with Inertia + Vue 3 + Tailwind v4, using Statamic 6's native `@ui` design-system components throughout. v0.2.x's Blade layer fought the design system; v0.3.0 stops fighting.

### Added

- **Inertia + Vue 3 CP layer.** All 8 pages (Dashboard, Contacts/Index, Contacts/Show, Followups/Index, Forms/Index, Forms/Edit, Tags/Index, Settings) are now Vue Single-File Components under `resources/js/pages/`, registered via `Statamic.$inertia.register('leadhub::PageName', Component)` in `resources/js/cp.js`.
- **Native `@ui` components everywhere.** Tables → `<Listing>`, forms → `<PublishForm>` with PHP-defined Blueprint tabs, inputs → `<Select>`/`<Combobox>`/`<DatePicker>`/`<Checkbox>`/`<Switch>`. No more custom `<select>` or hand-styled buttons.
- **Form-mapping editor** is now a Blueprint-driven `<PublishForm>` with two tabs (General + Field Mapping). Field handles are auto-discovered from the Statamic form's blueprint and presented as `<Select>` options (no more typing handles by hand).
- **Vite tooling.** Ships with `vite.config.js` + `package.json` so the host project's `npm run build` compiles the addon's CP assets.
- **Tailwind v4 setup** with explicit `@layer addon-theme` / `@layer addon-utilities` ordering — addon styles never fight Statamic's CP design system.

### Changed

- All controllers return `Inertia::render('leadhub::PageName', [...props])` instead of `view(...)`.
- Routes are unchanged; only the controller return type changed. Existing Pest tests pass.
- Translations are still registered under the `leadhub::` namespace (the v0.2.2 fix).
- Polled the new Statamic-6 patterns from the official `statamic/cms@6.x` source via the *Statamic 6 CP UI Patterns* skill (audited 2026-05).

### Removed

- All Blade views under `resources/views/` (replaced by Vue SFCs).
- `TimelineController` (the Show page now receives events as Inertia props with built-in pagination).

### Fixed

- **Translations actually load now.** The v0.2.2 attempt at registering the `leadhub::` namespace via `loadTranslationsFrom()` in `bootAddon()` silently failed because Statamic's boot resolves the translator service early — before our after-resolving callback fires — so the namespace was never added. v0.3 force-registers via `$translator->addNamespace()` directly, both eagerly and via `resolving()`. The CP now shows real text instead of raw `leadhub::nav.dashboard` keys.

### Upgrade notes

If you upgrade from v0.2.x:

1. `composer update goldnead/statamic-leadhub`
2. `npm install && npm run build` in your host Statamic project
3. `php artisan optimize:clear && php please stache:clear`

No data migration required. The eloquent and flat-file drivers continue to work unchanged.

## [0.2.1] — 2026-05-07

First green-CI release. v0.2.0 was structurally complete but had four bugs that only the new matrix surfaced — all four are fixed here.

### Added

- **GitHub Actions CI matrix** (`.github/workflows/tests.yml`) — runs the Pest suite across PHP 8.2 + 8.3 × Statamic 5.* + 6.* × eloquent + flat drivers (8 jobs total). All cells are passing on this release.
- `RepositoryBindingTest` Pest suite — verifies that the `LEADHUB_DRIVER` env var actually flips the container bindings, ensuring every matrix cell exercises a different code path.
- `TestCase::defineEnvironment()` now reads `LEADHUB_DRIVER` from env so the matrix can shift the default driver per job.

### Fixed

- **Composer install fails out of the box.** `pixelfear/composer-dist-plugin` (a transitive dependency of `statamic/cms`) was blocked by Composer 2.2+'s plugin allow-list. Added it (and `composer/installers`, `php-http/discovery`) to `config.allow-plugins` in `composer.json`. ([`9ed33ff`](https://github.com/goldnead/statamic-leadhub/commit/9ed33ff791634a3d3c9efcc4beec35b06b25a270))
- **Tags from form submissions were silently dropped.** `Eloquent::attach()` on the `leadhub_contact_tag` pivot threw a SQL error because `withTimestamps()` expected an `updated_at` column the migration didn't define — and the listener's outer `try/catch` swallowed it. Migration now includes `updated_at`. ([`0e649b9`](https://github.com/goldnead/statamic-leadhub/commit/0e649b97b1e7844e643383982813280c074a3b5a))
- **Driver binding ignored runtime config changes.** `ServiceProvider::register()` read `config('leadhub.storage.driver')` once and bound the concrete implementation eagerly. Tools like `orchestra/testbench`'s `defineEnvironment` hook (and runtime config changes) had no effect on the bindings. Driver selection is now wrapped in a closure so it resolves on every `app()` call. ([`870e9b6`](https://github.com/goldnead/statamic-leadhub/commit/870e9b6bd3c45eb514b6193b3cc09668eb107382))
- **Defensive: `default_tags` cast roundtrip.** `SubmissionMapper::extractTags()` now also accepts a JSON string for `default_tags` in case the cast leaks the unparsed JSON through. Belt-and-suspenders against driver/cast quirks. ([`f42a790`](https://github.com/goldnead/statamic-leadhub/commit/f42a790c74d78564f07aee360aeaf003b7f2aaa6))
- **Test setup bugs from v0.2.0:**
  - `Event::fake()` without arguments was wiping out Eloquent model events too, breaking auto-UUID generation in tests. Replaced with `Event::fake([…specific events…])`.
  - `ContactResolverTest > does not overwrite` made an incorrect assumption about Faker-generated `last_name`. Test now sets `last_name => null` explicitly so the "fill empty fields" rule applies.
  - `FollowupServiceTest` called `->get()` on `dueToday()`/`overdue()` which became `Collection` returns in v0.2 (used to be Builder). Removed the redundant `->get()`. ([`3a820b3`](https://github.com/goldnead/statamic-leadhub/commit/3a820b34104e060d6ea975fe4889678d539e6eaa))

## [0.2.0] — 2026-05-07

### Added

- **Optional flat-file storage driver.** LeadHub now ships with two drivers, switchable via `LEADHUB_DRIVER` env var or `config/leadhub.php`:
  - `eloquent` (default): the database-backed driver from v0.1.
  - `flat`: stores leads as YAML files under `content/leadhub/` with a Stache-style JSON index. True to Statamic's flat-file philosophy. Suitable for projects up to ~500 contacts.
- New `Goldnead\Leadhub\Contracts\Repositories\*` interfaces for all 6 entities. Domain services and controllers now depend only on these interfaces — neither driver leaks into the call sites.
- New `Goldnead\Leadhub\Repositories\Eloquent\*` and `Goldnead\Leadhub\Repositories\FlatFile\*` namespaces with concrete implementations.
- `php artisan leadhub:stache:warm` — rebuilds the flat-file indexes (with `--clear` to nuke and rebuild from scratch).
- `php artisan leadhub:storage:migrate --from={driver} --to={driver}` — moves contacts, events, notes, follow-ups, tags, and form mappings between drivers (`--dry-run` supported).
- New `FlatFileDriverTest` Pest suite covering create/find/paginate/tag-attach/follow-up flows on the flat-file driver.

### Changed

- `ContactResolver`, `TimelineService`, `FollowupService`, `TagService`, `ExportService`, the `CreateOrUpdateLeadFromSubmission` listener, and all 9 CP controllers were refactored to depend on repository interfaces instead of `Model::query()` calls.
- `ServiceProvider` now binds the right repository implementation to each interface based on the configured driver.
- Migrations are only registered when the eloquent driver is active. Flat-file users don't need to run `php artisan migrate`.

### Notes

The flat-file driver is feature-complete against the public API but is best treated as **beta** until it sees production smoke-testing. Open issues for any rough edges discovered.

## [0.1.0-mvp] — 2026-05-07

Initial public release. Implements the "First 80% Completion Definition" from the original PRD.

### Added

- Statamic addon scaffold with `Statamic\Providers\AddonServiceProvider`
- 7 database tables: `leadhub_contacts`, `leadhub_events`, `leadhub_notes`, `leadhub_tags`, `leadhub_contact_tag`, `leadhub_followups`, `leadhub_form_mappings`
- 6 Eloquent models with factories: `Contact`, `Event`, `Note`, `Tag`, `Followup`, `FormMapping`
- 5 domain services: `SubmissionMapper`, `ContactResolver`, `TimelineService`, `FollowupService`, `TagService`
- `CreateOrUpdateLeadFromSubmission` listener that hooks `Statamic\Events\SubmissionCreated`, fail-safe by design (errors never break the form submission flow)
- 11 internal Laravel events for future webhook / CRM sync integration
- Control Panel:
  - Dashboard with KPI cards, latest activity, due/overdue follow-ups, leads by status
  - Contacts index with filters (status, source, tag, follow-up, date range), search and pagination
  - Contact detail with timeline, sidebar fields, tag editor, status changer, archive/delete actions
  - Follow-ups index (today / overdue / upcoming)
  - Form mappings index + per-form mapping editor
  - Tags management
  - Settings overview
- Per-form mapping with email-required validation when enabled
- Email normalization (trim + lowercase) for deduplication, no aggressive Gmail rules
- Timeline payload redaction (configurable sensitive keys)
- CSV export with filter awareness; queued via `ExportContactsJob` past a configurable threshold
- 8 granular CP permissions registered under a "LeadHub" group
- LeadHubPolicy gates with super-user bypass
- DE + EN translation files
- Pest unit + feature tests for the entire domain layer
- README, MARKETPLACE copy, MIT license
