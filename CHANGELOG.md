# Changelog

All notable changes to `goldnead/statamic-leadhub` are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

_Nothing yet._

## [1.7.0] — 2026-07-28

LeadHub was a CRM you could not type into. Companies, tasks and opportunities
appeared in the navigation, read completely, and had no write routes at all —
every one of those records could only come into existence through a form
submission, the facade, or `tinker`. This release adds the missing half.

Also here: task assignment, which has been a column with a scope and no screen
since 1.1.

### Added

- **Create, edit and delete for companies, tasks and opportunities in the
  Control Panel.** Routes, controller actions, form requests, Vue screens,
  permissions and both locales. Entry points where a user actually looks: a
  "New company" button on the companies index, "New task" on the tasks index,
  "New opportunity" on the board — once in the header and once per column, so
  creating from a column carries that column's stage with it — plus "New task"
  and "New opportunity" on the contact page, prefilled with that contact.
  Opportunities got their own `OpportunityController`: `PipelineController` is
  already the board, the management screen, stage editing and the move
  endpoint.

  Three decisions are worth knowing about, and all three are in the code:

  - **Writes go through the services, not the models.** `TaskService::create()`,
    `OpportunityService::create()`, and `LeadHubCompanyCreated` fired by hand
    on the company path. A controller calling `Task::create()` directly would
    have produced records the webhook-manager bridge and the segment listeners
    never hear about — visible only as "the CP-created ones are missing", weeks
    later. Creating an opportunity straight into a terminal stage therefore
    closes it, instead of leaving an open deal in the "Won" column.
  - **Reference ids are validated through the models, never through
    `exists:`.** Laravel's `exists` rule compiles to a raw query builder
    statement, so it never passes a model and the `HasBrand` global scope does
    not apply — `exists:leadhub_contacts,id` cheerfully confirms a contact of
    another brand. `Http\Requests\Concerns\ResolvesCrmReferences` does every
    lookup through the model query instead. `CrmCrudBrandIsolationTest` fails
    on that specific point if it is changed back.
  - **Dates use the two normalizers from 1.6.0.**
    `resources/js/support/datetime.js` on the way out,
    `Support\DateValueNormalizer` through `NormalizesDatePickerValues` on the
    way in, and `granularity="minute"` rather than the `with-time` attribute
    that is not a prop of that component. This is the third time the CP date
    picker has come up; it is now the third place that handles it identically.

- **Deletion is refused while something still hangs on the record**, with a
  message that says what. A company with linked contacts or with opportunities
  cannot be deleted; an opportunity with tasks cannot be deleted; a task, which
  nothing references, deletes outright. This is the rule v1.5.0 established for
  pipeline stages, applied rather than reinvented. The alternatives were both
  worse: a hard delete cascades the contact links away and leaves every
  `opportunity.company_id` pointing at nothing (that FK does not cascade) plus
  timeline entries naming a company that is gone, and archiving would have
  added a third state to every list, filter and report permanently. Two tests
  per module, because a lock that is too tight is as much a defect as a missing
  one: one proving the refusal, one proving a record with nothing attached
  still deletes.

- **Task assignment reaches the screen.** An assignee column on the task list,
  an owner filter including "Unassigned", a "My tasks" toggle, and an assignee
  field on the create and edit forms. `assignee_id` has been a real column with
  `scopeForAssignee()` since 1.1 and `TaskController::index` has been handing it
  to the Vue page all along — nothing read it. Contacts have had all of this
  since 1.0 (`?mine=1`, `?assigned_to=`); tasks now use the same shapes so the
  two lists behave alike. Assignees are validated against
  `Support\UserDirectory::assignable()`, so a hand-crafted request cannot park
  work on an account that cannot open the module.

- **Three new permissions**: `manage leadhub companies`, `manage leadhub tasks`,
  `manage leadhub opportunities`, under `view leadhub`. The read side of these
  modules stays on `view leadhub`. Separate from the contact permissions on
  purpose: "may edit a contact" and "may delete the company behind fifty
  contacts" are not the same authority. **Upgrade note:** no existing role
  holds these, so the new buttons are invisible to non-super users until an
  administrator grants them. `POST /tasks/{task}/complete` deliberately still
  accepts `edit leadhub contacts` as well, so nobody loses "mark complete" in
  the meantime.

- **`GET /leadhub/contacts/options`** and `Support\ContactPicker` — a
  brand-scoped option feed for the contact pickers on the task and opportunity
  forms. The addon had no contact picker, and a `<Select>` over every contact
  stops working somewhere in the low thousands, so the forms get a first page
  and the CP `<Combobox>` queries the endpoint as you type.

- **`resources/js/support/ErrorSummary.vue`** — the collected error box above a
  form, for messages whose key is not a field on the screen (a refused
  deletion, most of all). Every new screen renders per-field errors through
  `<Field :error>` and this above it, and every write call has an `onError`
  branch. Same shape as `statamic-marketing` v1.5.3: one pattern across the
  addons rather than one per screen. A rejected input that looks like a dead
  button is the defect the QA run found most often; none of the new screens can
  produce it.

### Notes

- Suite: **324 passed + 4 skipped** on the eloquent driver, up from 268 + 4.
  56 new tests across `CompanyCrudTest`, `TaskCrudTest`, `OpportunityCrudTest`,
  `TaskAssignmentTest` and `CrmCrudBrandIsolationTest`, every one of them
  against the real route (request → controller), because the gap being closed
  was never a missing model — it was a missing HTTP surface, and a test against
  `Company::create()` would have passed for a year while the CP had no button.
- The flat driver keeps its 7 pre-existing failures (`ContactCreateTest` ×3,
  `CpRoutesTest`, `CrmSyncTest` ×2, `NotificationsTest`), unchanged in count and
  location with these changes stashed and applied. They are untouched here and
  tracked separately.
- **Not done, deliberately:** reassignment writes no timeline entry and fires no
  event. Contact assignment does both, but the equivalent for tasks needs a new
  `Event::TYPE_*` constant and a new webhook-manager trigger — a change to this
  addon's public surface, which does not belong in a UI release. Written up as
  gap 6 in `GAPS.md`.
- **Open, and named:** "assignees are the CP users of the respective brand"
  cannot be implemented today. `statamic-brand-context` scopes Eloquent models;
  a Statamic user is not one — there is no `brand_id`, no pivot, no per-brand
  role — so the assignable list is what `UserDirectory` can actually derive:
  everyone who may view LeadHub, in every brand. What *is* isolated is the
  work: the assignee filter never shows another brand's tasks, and there is a
  test for that. Gap 5 in `GAPS.md`.

## [1.6.0] — 2026-07-28

The two loose ends v1.5.0 wrote down instead of fixing: the third pivot's brand
column, and the German half of the CRM modules.

### Fixed

- **The brand column on segment membership was still documentation, not
  defense.** v1.5.0 made `brand_id` real on `leadhub_contact_company` and
  `leadhub_contact_tag` through `Models\Concerns\ScopesPivotToBrand`, which
  hangs on an Eloquent relation. `leadhub_segment_contact` carries the same
  column and the same promise in its migration comment, but membership is
  written and read by raw query-builder calls in `EloquentSegmentRepository` —
  there was no relation for that fix to attach to, so the column stayed inert.
  It is now stamped on every insert and filtered on every read: `membersCount`,
  `hasContact`, `handlesForContact`, `removeContact`, and the
  `Segment::contacts()` relation behind `memberIds()` and the member count on
  the index. As with the other two pivots this changes nothing while the models'
  global `BrandScope` is on; it is the protection that survives the paths where
  the scope is deliberately off — `BrandContext::withoutBrandScope()` for
  cross-brand reporting, and console commands iterating brands. There a
  mis-stamped row would otherwise hand brand A's segment a contact of brand B.
  `tests/Feature/SegmentContactPivotBrandTest.php` covers both directions and
  fails in six places the moment the filter is removed.

  Two decisions worth knowing about, both in the code:

  - `addContact()` checks for an existing row **without** the brand filter. The
    pivot's primary key is `(segment_id, contact_id)`, so a foreign-brand row
    cannot be joined by a second one; it is left untouched rather than
    re-stamped, because re-stamping would launder a cross-brand membership into
    the current brand.
  - `removeContact()` is filtered like the reads. A caller that cannot see a
    membership must not be able to delete it either — the same contract
    `withPivotValue()` gives `detach()` on the other two pivots.

  The resolution of "which brand does this pivot row belong to" moved into
  `Support\PivotBrand` so the relation path and the repository path cannot drift
  apart. It stays inert (no stamp, no filter) when no brand can be resolved at
  all, which is what keeps a fresh install mid-migration working.
- **New migration `2026_07_28_000001`** re-stamps segment memberships written
  since the v1.5.0 backfill. Those rows carry `NULL`, because the raw inserts
  never set the column — and a filter over a partially-NULL column does not
  raise an error, it makes members disappear from a segment that nobody
  changed. The migration runs immediately before the filter goes live, takes the
  brand from the owning segment, parks unresolvable rows on the default brand,
  and only touches rows that are still NULL, so it is safe to re-run.

### Added

- **German translations for the CRM modules.** `resources/lang/de/` had no
  `companies.php`, `tasks.php` or `pipelines.php`, so German installs showed
  those three modules in English in an otherwise German Control Panel — Laravel
  falls back key by key, which is why this never looked like an error. All three
  now exist in full, and the two files that had quietly fallen behind are caught
  up: `nav.php` was missing the three CRM entries, `timeline.php` the eight
  task, opportunity, merge and source-ingest lines added since 1.1.0.
- **`tests/Feature/TranslationParityTest.php`** compares `en/` and `de/` file by
  file and key by key, in both directions. An English string nobody translates
  now fails the suite instead of appearing in somebody's CP, and a German key
  whose English original was renamed is caught as the dead weight it is. This is
  the test that stops the gap from reopening; it fails in seven places against
  the pre-1.6.0 lang files.

### Notes

- Full suite green on the eloquent driver: **268 passed + 4 skipped** (up from
  227 + 4). The flat driver keeps its 7 pre-existing failures
  (`ContactCreateTest`, `CpRoutesTest`, `CrmSyncTest`, `NotificationsTest` —
  `FlatFileContactRepository` and `SendFollowupDigestCommand` underneath),
  unchanged in count and location before and after these changes; segment
  membership on flat is stored in the contact's YAML and has no pivot, so
  `SegmentContactPivotBrandTest` skips there.
- `GAPS.md` no longer lists either of these. What remains unbuilt is unchanged:
  CP create/edit/delete for companies, tasks and opportunities; task assignment
  beyond the data model; and the engagement score.

## [1.5.0] — 2026-07-27

Repairs from a full QA run against a live Hub instance. Five defects, each with
a test that fails without its fix, plus a written account of three things that
are simply not built yet (`GAPS.md`).

### Fixed

- **Follow-ups could not be created from the Control Panel.** The CP
  `<DatePicker>` is built on reka-ui: its `v-model` is an
  `@internationalized/date` *DateValue object*, never a string. `Contacts/Show.vue`
  posted it straight through, so `due_at` arrived as
  `{"calendar":{"identifier":"gregory"},"era":"AD","year":2026,…}` and Laravel's
  `date` rule answered "Not a valid date." — a 422 that `setFollowup()` had no
  `onError` branch to display. The date is now normalized before it is sent
  (`resources/js/support/datetime.js`) and again on arrival
  (`Support\DateValueNormalizer`, applied through
  `Http\Requests\Concerns\NormalizesDatePickerValues` on the store route and on
  the update route), and the field renders whatever validation still rejects.
  The picker also carried an inert `with-time` attribute — not a prop of that
  component — so a follow-up could never be given a time; it is now
  `granularity="minute"`. Covered against the real HTTP route, not the model:
  `tests/Feature/FollowupDatePickerTest.php`.
- **The contact detail page showed none of the contact's CRM records.**
  `ContactController::show` passed no props for companies, tasks or
  opportunities, so all three were invisible regardless of the feature flags.
  They are now three panels, each rendered whenever its module is on — including
  when empty, so "nothing linked" is distinguishable from "not built". The
  free-text `company` column and a linked `Company` record are two different
  things that look alike; the page now says which one it is showing.
  `tests/Feature/ContactShowCrmPanelsTest.php`.
- **Winning a deal made it disappear from the board.** The Kanban query filtered
  on `open()`, so a closed opportunity vanished and its terminal column summed
  to 0 — from the operator's seat, indistinguishable from data loss. Closed
  deals now stay in their terminal stage for a selectable window (open only /
  30 / 90 / 365 days / all, default 30 days, carried in `?closed=`), cards are
  badged won or lost with their closing date, the stage total counts them, and
  the board header carries open / won / lost totals. Widening the status filter
  does not widen brand access — asserted across two brands in
  `tests/Feature/PipelineBoardClosedDealsTest.php`.
- **`brand_id` on the pivot tables was documentation, not defense.** The
  brand-scoping migration justified the denormalized column as "query-time
  defense", then never stamped or read it. Decision: keep it and make it real.
  `Models\Concerns\ScopesPivotToBrand` stamps the brand on every attach and
  constrains every read of `leadhub_contact_company` and `leadhub_contact_tag`,
  which is the only protection that survives the paths where the models' own
  `BrandScope` is deliberately switched off (`BrandContext::withoutBrandScope()`
  for cross-brand admin and reporting, and per-brand console commands). A new
  migration re-stamps the rows written since the column was added, so no
  existing link disappears behind the new filter.
  `tests/Feature/ContactCompanyPivotBrandTest.php` includes the cross-brand case
  that fails the moment the pivot filter is removed.
- **Pipeline stages could not be ordered or edited.** "Add stage" only appended,
  and nothing could be renamed, moved or deleted afterwards — a stage that
  landed behind the terminal ones could only be fixed by rebuilding the whole
  pipeline. The management screen now edits stages in place, moves them up and
  down, appends and deletes them, and saves the order in one request. A partial
  reorder is refused rather than half-applied, a stage still holding
  opportunities is not deleted, and a pipeline cannot keep fewer than one stage.
  `tests/Feature/PipelineStageManagementTest.php`.

### Added

- `POST /cp/leadhub/pipelines/{pipeline}/stages`,
  `POST …/stages/reorder`, `PATCH …/stages/{stage}` and
  `DELETE …/stages/{stage}` — all behind `manage leadhub settings` and the
  `features.pipelines` flag, resolving the pipeline through the brand-scoped
  query.
- `GAPS.md` — what is *not* built: CP create/edit/delete for companies, tasks
  and opportunities; task assignment beyond the data model; and the engagement
  score, which computes correctly and appears on no screen. Per gap: affected
  files, prerequisites, the decisions to settle first, and an effort estimate.

### Notes

- Full suite green on the eloquent driver: **227 passed + 4 skipped** (up from
  192 + 4). The flat driver keeps its 7 pre-existing failures
  (`FlatFileContactRepository`, `SendFollowupDigestCommand`), unchanged in count
  and location before and after these fixes; the CRM-core modules are
  eloquent-only and skip there.
- Still open, recorded in `GAPS.md`: `leadhub_segment_contact` is written and
  read through raw `DB::table()` queries in `EloquentSegmentRepository`, so its
  `brand_id` is backfilled by the new migration but neither stamped on new rows
  nor read. Same defect class as the two pivots fixed here; it needs repository
  work rather than a relation change.
- `resources/lang/de/` still has no `companies.php`, `tasks.php` or
  `pipelines.php`; German installs fall back to English for the CRM modules.
- 1.2.0 through 1.4.0 were tagged without changelog entries. Those releases are
  not reconstructed here.

## [1.1.0] — 2026-07-03

### Added — Segments

- **Dynamic contact segments.** A new first-class entity: named, rule-based groups of contacts whose membership updates itself. Rules are a boolean `all` / `any` tree (groups nest) over three condition types:
  - **`field`** — any contact column (`status`, `source`, `source_form`, `assigned_to`, `engagement_score`, `do_not_contact`, `created_at`, `last_activity_at`, `full_name`, `first_name`, `last_name`, `email`, `company`, `utm_*`) with a full operator set (`eq`, `neq`, `in`, `not_in`, `contains`, `starts_with`, `gt`/`gte`/`lt`/`lte`, `is_set`, `is_empty`, `is_true`, `is_false`, `before`, `after`, `within_days`, `older_than_days`).
  - **`tag`** — `has` / `has_not` a tag (by id, slug, or name).
  - **`event`** — `has` / `has_not` a timeline event key, optionally scoped `within_days`.
- **Driver-agnostic evaluation.** Contact facts are assembled through the repositories (tags via `TagRepository::forContact`, events via `EventRepository`), never through Eloquent relations — so evaluation is correct under both the `eloquent` and `flat` drivers. Whole-segment resolution iterates the contact set (chunked for eloquent, index-driven for flat); single-contact evaluation is a cheap reactive path.
- **Materialized membership, kept fresh two ways.** Eloquent stores membership in a `leadhub_segment_contact` pivot; flat mirrors segment handles onto each contact's YAML. A listener (`ReevaluateSegmentMembership`) re-evaluates the mutated contact on `LeadHubContactCreated/Updated`, `LeadHubStatusChanged`, `LeadHubTagAdded/Removed` and `LeadHubSourceIngested`; a scheduled `leadhub:segments:sweep` command re-materializes time-based rules daily.
- **New lifecycle events.** `LeadHubContactEnteredSegment` and `LeadHubContactLeftSegment` fire on membership diffs (metadata carries `segment_handle` / `segment_id`) and are auto-registered as Webhook Manager triggers `leadhub.segment.entered` / `leadhub.segment.left`.
- **Loop protection.** A per-contact re-evaluation depth guard (`SegmentService::MAX_DEPTH = 1`) prevents infinite cascades when a consumer reacts to an enter/leave event by mutating the same contact (e.g. adding a tag). Documented in the class.
- **Public facade contract.** `LeadHub::segments()`, `LeadHub::segmentMemberIds(string $handle): array` (returns contact UUIDs, resolved live from the rules), and `LeadHub::contactInSegment($contactOrId, string $handle): bool`. This is the stable surface sibling addons (e.g. campaign audience narrowing in `statamic-marketing`) build on. Guard with `method_exists(LeadHub::getFacadeRoot(), 'segmentMemberIds')` for graceful degradation on older LeadHub.
- **Control Panel.** A Segments index and a create/edit rule builder (condition rows for field/tag/event, `all`/`any` matching, live "matching contacts" member-count preview), plus two new permissions (`view leadhub segments`, `manage leadhub segments`) and a nav entry. Permission checks go through `$user->can(...)` so they work on eloquent-users sites.
- **Rules cast.** A dedicated `Casts\SegmentRules` accepts BOTH a stored JSON string (eloquent) and an already-decoded PHP array (flat hydration, and in-memory `new Segment(['rules' => [...]])`), fixing the `Json::decode`-on-array crash the built-in `array` cast would have caused.

### Notes

- Full suite green on the eloquent driver: **161 passed + 4 skipped** (up from 130 + 4). New coverage: every condition type and `all`/`any` nesting, both drivers, reactive enter/leave, the scheduled sweep, the loop guard, the facade contract, and eloquent-user CP compatibility for the new routes. The flat matrix adds one documented skip for an unrelated pre-existing `Tag::id` cast quirk.

## [1.0.1] — 2026-07-02

### Fixed

- **Eloquent-users compatibility.** CP controllers and the `LeadHubPolicy` called Statamic-only methods (`hasPermission()`, `isSuper()`, `id()`) on the raw authenticated user. On sites using the eloquent users repository the auth user is a plain model (e.g. `App\Models\User`), so every LeadHub CP page crashed with a `BadMethodCallException`. Permission checks now go through Laravel's Gate (`$user->can()`, which Statamic wires up via `Gate::after` for both user drivers), the policy resolves supers via `User::fromUser()`, and user IDs are read with `getAuthIdentifier()`. Regression-tested with `statamic.users.repository=eloquent` and a plain `Authenticatable` model.

## [1.0.0] — 2026-06-30

First stable release — the complete LeadHub feature set on Statamic 6, installable with `composer require` + `php artisan migrate` out of the box.

### Added — core

- **Contacts, timeline & follow-ups.** Statamic form submissions become contacts: repeated inquiries are merged by e-mail, every submission and note is recorded on a per-contact timeline, and simple follow-ups can be set, listed and completed. Contacts carry statuses, tags and notes, and are filterable and searchable in the Control Panel, with a dashboard of KPIs, due follow-ups and latest activity.
- **Public API — `LeadHub` facade + ingestion.** A documented facade (`Goldnead\Leadhub\Facades\LeadHub` → `LeadHubManager`) is the supported entry point for host apps. A generic ingestion API (`SourceEvent` + `IngestionService`) lets any source — not just Statamic forms — create or update a contact and append a timeline entry; a `dedupe_key` makes ingestion idempotent so the same event can be replayed safely.
- **Dual storage drivers.** Every repository is contract-backed with two interchangeable drivers: `eloquent` (default, database) and `flat` (Statamic-native YAML files). Switch with `leadhub:storage:migrate` (with `--dry-run`); the public API is driver-agnostic.
- **Granular CP permissions and a native funnel nav icon.**

### Added — CRM-core modules (opt-in, behind feature flags)

- **Lead scoring & contact merge.** A configurable scoring service ranks contacts by activity; a merge service consolidates duplicate contacts (and their timelines) safely.
- **Companies.** Contacts resolve to companies (`CompanyResolver`), giving an organisation-level view over individual leads.
- **Tasks.** Lightweight task records tied to contacts, managed in the CP.
- **Pipelines, stages & opportunities.** A Kanban board over configurable pipelines/stages, with opportunities that move between stages; stage transitions are recorded, and `leadhub:followups:fire-due` fires due follow-ups.
- **Lead assignment + e-mail notifications.** Assign an owner (any user with `view leadhub`) to a contact from the detail page; the change is timelined and the contacts list is filterable by `?mine`, `?assigned_to=<id>` and `?assigned_to=none`. Three opt-in Laravel notifications — new lead, lead assigned, and a scheduled daily follow-up digest (`leadhub:followups:digest`). Gated by `features.notifications`; recipients and digest time live under `notifications.*`. Sending is fail-safe.
- **Marketing attribution.** When `features.attribution` is on, UTM parameters, referrer and landing page are captured from the originating submission onto the contact and shown in an Attribution panel. Field mapping is configurable via `attribution.fields`.
- **CRM connectors + Sync log.** Push contacts to external systems on create / update / status change via pluggable drivers — `hubspot`, `brevo`, and a generic HMAC-signable `webhook` driver — declared under `crm.destinations` and gated by `features.crm_destinations`. Syncs run on the queue, are retried with backoff, and are recorded both on the contact timeline and in a dedicated **Sync log** CP page. Host apps can register custom drivers via `DestinationManager::extend()`. The flat-file driver degrades gracefully when the log table is absent.
- **Outbound event surface + Webhook Manager bridge.** The full set of `LeadHub*` lifecycle events is a public integration point. When [goldnead/statamic-webhook-manager](https://github.com/goldnead/statamic-webhook-manager) is installed, LeadHub auto-registers all eleven events as webhook-manager triggers (e.g. `leadhub.status.changed`) and re-emits them as `TriggerDetected` — no glue code. The bridge (`src/Integrations/WebhookManager/`) is fail-safe, loads the addon's classes only when present, and is toggleable via `features.webhook_manager`. Without that addon, the built-in `webhook` CRM driver covers a direct JSON POST.

### Added — tooling

- `scripts/setup-playground.sh` — builds a persistent, runnable Statamic 6 playground with the addon wired in as a path repository, for local CP testing and development.

### Fixed

- **Webhook Manager bridge silently registered zero triggers when LeadHub booted before webhook-manager.** Sibling addon boot order is not guaranteed; when LeadHub's provider booted first, the `webhook-manager` container binding didn't exist yet, so all 14 trigger registrations failed ("Target class [webhook-manager] does not exist", swallowed as warnings). The bridge boot is now deferred into an `app->booted()` callback with a guarded end-of-queue retry (covering any package discovery order), the bridge is a container singleton, and an idempotency guard ensures a repeated boot never double-registers triggers or listeners.
- **Installation failed on a fresh Statamic 6 project.** The default Statamic 6 skeleton now ships Laravel 13, but the framework constraint capped at `^11.0|^12.0`, so `composer require` could not resolve. Widened to `^11.0|^12.0|^13.0` (and `orchestra/testbench` to allow `^11.0` for the dev suite). Verified resolving against `laravel/framework v13.17` + `statamic/cms v6.23`.
- **Every Control Panel page returned HTTP 500 (`Vite manifest not found`).** The compiled CP assets were never shipped — `public/build` was gitignored and there is no mechanism by which the host project's `npm run build` compiles an addon's entries. Adopted the official Statamic 6 addon Vite convention (`@statamic/cms/vite-plugin`, output to `resources/dist/`) and now **ship the compiled assets in the package**, which Statamic publishes to the host's `public/vendor/` on install. No end-user build step is required.
- **Saving tags on the contact detail page threw a 500.** `ContactController::update()` filled the `tag_ids` array onto the contact model, which tried to persist a non-existent `tag_ids` column. Tags are now synced to the tag relation only (covered by a new regression test).
- **The contact detail page never showed an active follow-up or the contact's tags.** It read them from eager-loaded relations, but `find()` (unlike `paginate()`) doesn't load relations — so the active follow-up always showed as "none" and tag checkboxes were never ticked. Both are now fetched through the driver-agnostic repositories.

### Changed (UI)

- The Control Panel styling now matches core Statamic pixel-for-pixel: the addon imports Statamic's Tailwind theme (previously it shipped the bare framework, so design tokens silently produced no CSS and a stray Preflight reset fought the CP). Dashboard, contact detail and follow-up pages were rebuilt on native `Panel`/`Card` composition.
- Added a LeadHub addon icon and a matching funnel Control Panel nav icon.

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
