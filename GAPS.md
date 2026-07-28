# GAPS

Three things this addon does not do. They are not defects — nothing is broken,
the code behaves exactly as written. They are unbuilt surfaces, found in a QA
run against a live Hub instance on 2026-07-27 and written down here so the next
person can start building instead of repeating the analysis.

Everything below refers to the eloquent driver. The CRM-core modules
(companies, tasks, pipelines) are eloquent-only by design and sit behind the
`features.*` flags in `config/leadhub.php`.

State as of v1.6.0.

---

## 1. Companies, tasks and opportunities cannot be created, edited or deleted in the CP

### What exists

| Entity | Routes today (`routes/cp.php`) | Controller |
|---|---|---|
| Company | `GET /companies`, `GET /companies/{company}` | `CompanyController::index`, `show` |
| Task | `GET /tasks`, `POST /tasks/{task}/complete` | `TaskController::index`, `complete` |
| Opportunity | `POST /pipelines/opportunities/{opportunity}/move` | `PipelineController::move` |

The data model behind all three is complete and covered by tests. The service
layer is complete too: `Services\CompanyResolver` resolves and links companies,
`Services\TaskService` creates and completes tasks, `Services\OpportunityService`
creates and updates opportunities, `Services\StageTransitionService` moves them.
The facade exposes the same through `LeadHub::upsertOpportunity()` and friends.

### What is missing

Write routes, write controller actions, and the Vue screens that call them. In
the Control Panel every one of these records can currently only come into
existence through a form submission, the facade, or `php artisan tinker`. There
is not a single "New company" or "New task" button anywhere in the CP.

### Files that would be touched

- `routes/cp.php` — add `create`/`store`/`edit`/`update`/`destroy` for
  `companies` and `tasks`; add `store`/`update`/`destroy` for opportunities
  under the `pipelines` prefix (`move` already lives there).
- `src/Http/Controllers/Cp/CompanyController.php`,
  `src/Http/Controllers/Cp/TaskController.php` — the new actions.
- `src/Http/Controllers/Cp/OpportunityController.php` — new. Opportunity CRUD
  does not belong in `PipelineController`, which is already the board, the
  management screen, stage editing and the move endpoint.
- `src/Http/Requests/` — `StoreCompanyRequest`, `UpdateCompanyRequest`,
  `StoreTaskRequest`, `UpdateTaskRequest`, `StoreOpportunityRequest`,
  `UpdateOpportunityRequest`. The task and opportunity requests carry date
  fields and must use `Http\Requests\Concerns\NormalizesDatePickerValues`, or
  they will reproduce the follow-up 422 exactly.
- `resources/js/pages/Companies/` — `Create.vue`, `Edit.vue`; buttons in
  `Index.vue` and `Show.vue`. `resources/js/pages/Contacts/Create.vue` is the
  closest existing pattern (`errors` ref + `onError` + `Field :error`).
- `resources/js/pages/Tasks/Index.vue` — inline create row or a `Create.vue`.
- `resources/js/pages/Pipelines/Board.vue` — a "New opportunity" entry point per
  column, and an edit affordance on the card.
- `resources/js/pages/Contacts/Show.vue` — "Add task" / "Add opportunity" /
  "Link company" from the panels added in v1.5.0, which is where a user will
  look first.
- `src/Policies/LeadHubPolicy.php` + `resources/lang/*/permissions.php` — see
  the decision on permissions below.
- `resources/dist/build/` — rebuild and commit (`npm run build`), see
  `scripts/check-dist-fresh.sh`.

### Prerequisites

- Company deletion has to decide what happens to `leadhub_contact_company` rows
  and to opportunities carrying `company_id`. The pivot cascades on delete; the
  opportunity FK does not.
- Task and opportunity creation from a contact page needs the contact in
  context; from the index it needs a contact picker. There is no contact-picker
  component in the addon yet; the CP `Combobox` plus a search endpoint on
  `ContactController` would be the smallest thing that works.
- Every write path must fire the existing events
  (`LeadHubCompanyCreated`, `LeadHubTaskCreated`, `LeadHubOpportunityCreated`)
  and write the timeline through `Services\TimelineService`, otherwise the
  Webhook Manager bridge and the segment re-evaluation listeners silently miss
  records created via the CP but catch the ones created via the facade. This is
  the single most likely thing to be forgotten.
- Brand scoping is automatic through `HasBrand` on create, but any raw pivot
  write needs the brand stamped by hand — compare `Services\CompanyResolver::link()`
  and `Models\Concerns\ScopesPivotToBrand`.

### Decisions to make first

1. **Permissions.** Today everything CRM-core rides on `view leadhub` and
   `edit leadhub contacts`. Either keep that (fast, coarse: whoever may edit a
   contact may delete a company) or introduce
   `manage leadhub companies` / `manage leadhub tasks` / `manage leadhub opportunities`.
   Separate permissions mean touching the policy, the lang files and every
   `canManage` prop. Decide before writing controllers, not after.
2. **Deletion semantics.** Hard delete, or the archive pattern contacts already
   use (`archived_at`)? Companies and opportunities are referenced from
   timelines; a hard delete leaves timeline entries pointing at nothing.
3. **Where opportunities are created.** From the board column (fast, stage is
   implied) or from the contact (correct, but two clicks further away). Both is
   more UI than it sounds, because the two paths need different defaults.
4. **Whether the company free-text field and linked companies converge.**
   `leadhub_contacts.company` is a string off the form; `linkedCompanies` are
   records. v1.5.0 makes the difference visible but does not resolve it. A
   "promote this text to a company record" action is the obvious next step and
   changes what the create form needs to look like.

### Effort

Roughly 3 to 4 focused days for all three entities, assuming the permission
question is decided up front:

- Companies CRUD: ~1 day (simplest, no date fields, no stage logic).
- Tasks CRUD: ~1 day, of which a good half is the assignee picker and the date
  field.
- Opportunity CRUD: ~1 to 1.5 days — stage/pipeline selection, value and
  confidence, and the interaction with `StageTransitionService` when creating
  directly into a terminal stage.
- Tests: ~0.5 days on top, mirroring `tests/Feature/PipelineStageManagementTest.php`.

Companies alone, as a first slice, is about a day and unblocks the "promote
free text to record" action that the contact page is now asking for.

---

## 2. Task assignment exists only in the data model

### What exists

`leadhub_tasks.assignee_id` is a real column, `Models\Task::scopeForAssignee()`
filters on it, `Services\TaskService::dueToday()` and `overdue()` both accept an
assignee, and `TaskController::index` already puts `assignee_id` into every row
it hands the Vue page. The contact page shows the assignee name per task since
v1.5.0.

### What is missing

Nothing reads or writes it in the CP. `resources/js/pages/Tasks/Index.vue`
receives `assignee_id` and renders no column for it. There is no filter, no
"my tasks" view, and no way to assign or reassign a task. The only route that
mutates a task is `complete`.

Contacts have all of this already — `ContactController::index` supports
`?mine=1`, `?assigned_to=<id>` and `?assigned_to=none`, and
`Support\UserDirectory::assignable()` supplies the option list. Tasks were never
given the same treatment.

### Files that would be touched

- `src/Http/Controllers/Cp/TaskController.php` — accept `mine` / `assignee_id`
  filters in `index()` (copy the block in `ContactController::index`, lines
  around the `assigned_to` filter), pass `assignableUsers` and an
  `assignee_name` per row via `Support\UserDirectory::label()`.
- `routes/cp.php` — a `PATCH /tasks/{task}` (or a narrow
  `POST /tasks/{task}/assign`) for reassignment.
- `resources/js/pages/Tasks/Index.vue` — an Assignee column, an owner filter,
  and a "my tasks" toggle. `resources/js/pages/Contacts/Index.vue` has the same
  three things and is the template.
- `resources/lang/en/tasks.php` **and** `resources/lang/de/tasks.php` — both
  exist since v1.6.0 and `tests/Feature/TranslationParityTest.php` fails if a
  new key lands in only one of them.
- Optionally `src/Notifications/` — contacts get `LeadAssignedNotification`;
  tasks have no equivalent.

### Prerequisites

- Assignee ids are strings, not integers: Statamic file users have UUIDs and
  eloquent users have numeric ids cast to string. `Support\UserDirectory` is the
  only place that knows how to resolve either — do not query a user model
  directly (see the v1.0.1 changelog entry for what happens when this is
  ignored).
- The dashboard already surfaces follow-ups. Decide whether "my tasks" belongs
  there too, or the two lists will diverge.

### Decisions to make first

1. **Does assignment notify?** Contacts do (`LeadHubNotifier::assigned()`, gated
   by `features.notifications`). If tasks should too, an event and a
   notification class are needed and the digest command becomes a candidate for
   including tasks — which is a larger change than the UI itself.
2. **Does assignment go on the timeline?** Contact assignment does
   (`TimelineService::recordAssigned`). Task assignment has no event type;
   adding one means a new `Event::TYPE_*` constant and a webhook-manager
   trigger, which is a public-surface change.
3. **Default filter.** `open` today. If a "mine" view exists, is it the default
   for non-super users? That changes what people believe their task list is.

### Effort

About 1 day for column, filter and "my tasks" plus reassignment; a second day
if notification and timeline entry are wanted, because both touch the public
event surface and need tests for the webhook bridge.

---

## 3. Engagement scoring computes, and is invisible

### What exists

Scoring works. `Services\ScoringService` applies the point table from
`config/leadhub.php` (`scoring.events`, `scoring.default`), writes
`leadhub_contacts.engagement_score`, and fires
`Events\LeadHubContactScoreChanged`. `Listeners\ScoreContactOnActivity` wires it
to activity. It is covered by `tests/Feature/ContactScoreChangedTest.php`, and
the QA run watched a contact go from 0 to 10.

### What is missing

The number never reaches a screen. Grep for `engagement_score` in
`resources/js/`: the only hit is a selectable field in the segment rule builder
(`resources/js/pages/Segments/Edit.vue`). It is not on the contact detail page,
not a column on the contacts index, not sortable, not filterable, not on the
dashboard, and a score change writes no timeline event — so a contact's score
history does not exist anywhere.

Three separate gaps that are easy to conflate:

- **Display.** No score anywhere in the UI.
- **Rule management.** The point table lives in `config/leadhub.php` only. There
  is no CP screen for it, so changing what scores a point is a deploy.
- **History.** `LeadHubContactScoreChanged` fires but nothing listens for the
  purpose of recording it. The timeline has no `score_changed` entry type.

### Files that would be touched

Display:
- `src/Http/Controllers/Cp/ContactController.php` — `engagement_score` into the
  `show()` contact payload and into the `index()` rows; a `Column::make('engagement_score')`
  and a `score_min` / `score_max` filter in the `$filters` array (the repository
  filter list in `src/Repositories/Eloquent/EloquentContactRepository.php` has
  to learn it too).
- `resources/js/pages/Contacts/Show.vue` — a value in the Details panel.
- `resources/js/pages/Contacts/Index.vue` — column + filter.
- `src/Http/Controllers/Cp/DashboardController.php` — optional "top scored
  leads" tile.

History:
- `src/Services/TimelineService.php` — a `recordScoreChanged()` method.
- `src/Models/Event.php` — a `TYPE_SCORE_CHANGED` constant.
- `resources/lang/en/timeline.php` and `resources/lang/de/timeline.php` — the
  summary line, in both locales (the parity test insists).
- A listener on `LeadHubContactScoreChanged`, registered in the provider's
  `$listen`.

Rule management:
- `routes/cp.php`, a `ScoringController`, and a Vue screen.
- Storage for the rules. This is the real decision, see below.

### Prerequisites

- Scoring is off by default (`features.scoring => false`). Every UI element has
  to be gated on that flag, or installs that never enabled it grow a column full
  of zeros.
- A timeline entry per score change is noisy: the score moves on almost every
  activity, and the timeline is already the busiest thing on the contact page.
  Either aggregate (one entry per day, or only on threshold crossings) or accept
  the noise deliberately.
- Scores are brand-scoped through the contact; a rule-management screen would
  need to decide whether rules are per brand or global. Config is global today.

### Decisions to make first

1. **Where do the rules live?** Staying in `config/leadhub.php` means no CP
   screen and no per-brand rules, but keeps scoring reproducible and
   deployable. Moving them to the database means a new table, a migration, a
   brand column, a merge strategy against the config defaults, and a decision
   about what happens to existing scores when a rule changes. This is the
   largest single decision in this document and it should be made before any
   display work, because a config-only answer makes gap 3 a half-day job and a
   database answer makes it a week.
2. **Is the score shown as a number or as a band?** "83" invites the question
   what 83 means. "Hot / warm / cold" needs thresholds, which are themselves
   configuration.
3. **Does a score change belong on the timeline at all,** or only threshold
   crossings? See the noise note above.

### Effort

- Display only (contact page, index column, filter, dashboard tile), rules
  staying in config: **~0.5 to 1 day.** This is the honest first slice, and it
  is what turns a working feature into a visible one.
- Plus timeline history with aggregation: **+1 day.**
- Plus a CP rule-management screen with database-backed, brand-scoped rules:
  **+3 to 4 days,** including the migration, the config-merge strategy, and the
  question of recomputation.

---

## Closed since this document was written

Two observations from the same QA run were recorded here as bugs rather than
gaps. Both were fixed in **v1.6.0** and are listed only so nobody looks for them
again:

- `leadhub_segment_contact` now has its `brand_id` stamped on every insert and
  filtered on every read in `EloquentSegmentRepository` and in
  `Segment::contacts()`, with a second backfill migration
  (`2026_07_28_000001`) for the rows written between the two releases.
  `tests/Feature/SegmentContactPivotBrandTest.php`.
- `resources/lang/de/` has `companies.php`, `tasks.php` and `pipelines.php`, and
  the gaps that had opened in `nav.php` and `timeline.php` are filled.
  `tests/Feature/TranslationParityTest.php` compares both locales key by key in
  both directions, so this cannot reopen quietly.

Note for gap 1 above: every new CRM screen brings new strings with it, and the
parity test means an untranslated one fails the suite. Write the German
counterpart in the same commit as the English original.
