# Changelog

All notable changes to `goldnead/statamic-leadhub` are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

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
