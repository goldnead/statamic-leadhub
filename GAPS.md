# GAPS

Things this addon does not do. They are not defects — nothing is broken, the
code behaves exactly as written. They are unbuilt surfaces, found in QA runs
against a live Hub instance on 2026-07-27 and 2026-07-28 and written down here
so the next person can start building instead of repeating the analysis.

Everything below refers to the eloquent driver. The CRM-core modules
(companies, tasks, pipelines) are eloquent-only by design and sit behind the
`features.*` flags in `config/leadhub.php`.

State as of v1.8.0. Gaps 1, 2 and 3 are closed; what closing them turned up is
written down as gaps 5, 6 and 9.

---

## 1. Companies, tasks and opportunities cannot be created, edited or deleted in the CP

> **Closed in v1.7.0 (2026-07-28).** Create, edit and delete exist for all three
> modules: routes, controllers, form requests, Vue screens, three new
> permissions (`manage leadhub companies` / `… tasks` / `… opportunities`), and
> both locales. Writes run through the services so the events and timeline
> entries fire on the CP path too. Deletion is refused while records still hang
> on the target, per decision L1 — the pipeline-stage rule from v1.5.0, applied
> rather than reinvented. Reference ids are validated through the models, never
> through `exists:`, because that rule bypasses the brand scope. Covered by
> `CompanyCrudTest`, `TaskCrudTest`, `OpportunityCrudTest` and
> `CrmCrudBrandIsolationTest`. The four questions below were decided as:
> separate permissions; refuse rather than archive or hard-delete; opportunities
> created from the board column (stage implied) and from the contact page; the
> free-text/record convergence deliberately left alone — see gap 7.

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

> **Closed in v1.7.0 (2026-07-28).** Assignee column, owner filter (including
> "Unassigned"), a "My tasks" toggle, and an assignee field on the create and
> edit forms — the same shapes `ContactController::index` has used since 1.0.
> Assignees are validated against `Support\UserDirectory::assignable()`.
> `TaskAssignmentTest`. Of the three questions below: assignment does **not**
> notify and does **not** write a timeline entry (gap 6), and `open` remains the
> default filter — "my tasks" is a toggle, never the default, because silently
> narrowing the list changes what people believe their task list is.

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

> **Closed in v1.8.0 (2026-07-29).** All three sub-gaps below are built:
> **display** (contact detail, contact list column, server-side score range
> filter and sort, all gated on `features.scoring`), **rule management** (the
> `leadhub_scoring_rules` table, a CP screen under LeadHub → Scoring with
> create/edit/enable/disable/delete, and a `manage leadhub scoring` permission)
> and **history** (`Event::TYPE_SCORE_CHANGED`, `TimelineService::recordScoreChanged()`,
> the `RecordScoreChangeOnTimeline` listener, both locales, and
> `leadhub.score.changed` registered as a webhook-manager trigger).
>
> The decisions the section below asks for were made as: **rules go to the
> database, brand-scoped** (decision L2 — the reason is per-brand rules, not the
> editing); **the score is shown as a number**, not a band, because thresholds
> would be a second piece of configuration to invent and nobody asked for one;
> **every real change gets a timeline entry**, not an aggregate, because a
> summarized history cannot answer "what awarded these 3 points" — with
> `leadhub.scoring.timeline` to turn it off.
>
> The recomputation question the section raises is answered by not answering it:
> changing a rule affects future activity only, existing scores stand, and the
> screen says so. Recomputing a running total from rules would need a full
> activity history per contact, which the timeline is but the score is not.
>
> Upgrade safety is the fallback in `ScoringService::rulesFor()`: while a brand
> has no rules, the config file still decides, so 1.7.0 → 1.8.0 changes no
> score. `php artisan leadhub:scoring:import --dry-run` shows what the import
> would write. Covered by `ScoringRuleCrudTest`, `ContactScoreVisibilityTest`,
> `ScoreTimelineEntryTest`, `ScoringRuleImportCommandTest` and
> `ScoringRuleBrandIsolationTest`.

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

## 4. The Control Panel screens are English in every locale

### What exists

`resources/lang/en/` and `resources/lang/de/` are complete against each other
since v1.6.0, and `tests/Feature/TranslationParityTest.php` keeps them that way.
Everything rendered on the PHP side is therefore properly localized: the nav
entries, the listing column labels, the filter option labels the controllers
build, the flash messages, the timeline summaries.

### What is missing

Everything rendered on the Vue side. The pages call Statamic's JS translator
with the English string as the key —

```js
<Header :title="__('Tasks')" icon="tasks" />
{ value: 'overdue', label: __('Overdue') },
```

— and that helper reads Statamic's **string** translations (`lang/de.json`),
not the `leadhub::` namespace. This addon ships no JSON lang file in any
locale, and Statamic's own `de.json` does not carry these strings, so every
page heading, panel heading, empty state, button and inline label stays
English. On a German install that produces a half-translated screen: German
navigation, German timeline, English headings.

This is not a CRM-module problem. Contacts, follow-ups and segments have it
too — the modules that were believed to be translated. It only became visible
while proving out the CRM translations in v1.6.0, because there the two layers
sit next to each other on the same screen.

### Files that would be touched

- `resources/lang/en.json` and `resources/lang/de.json` — new. Statamic and
  Laravel both auto-load `{locale}.json` from a package's lang path once it is
  registered, which `ServiceProvider` already does.
- Nothing in the Vue pages, if the keys stay the English source strings. That
  is the cheap version and the reason to do it this way.
- `tests/Feature/TranslationParityTest.php` — extend it to the JSON files, or
  the new layer drifts exactly like the PHP one did.

### Prerequisites

- The key set has to be harvested from the components, not guessed. Every
  `__('…')` call in `resources/js/` is a key, including the ones inside
  computed labels and `:text` bindings.
- Some strings carry placeholders in the Statamic style (`:count`); those must
  survive the extraction.
- Decide whether the addon should also translate strings that Statamic's own
  `de.json` already covers ("Save", "Cancel", "Delete"). Duplicating them is
  harmless but makes the file larger than the addon's own vocabulary.

### Decisions to make first

1. **English source strings as keys, or dotted keys?** Source strings are far
   less work and match how the pages are written today. Dotted keys are tidier
   and survive copy edits to the English text, but mean touching every
   component. Only worth it if the Vue layer is being reworked anyway.
2. **Which locales?** German is the only one the addon claims today. Adding a
   third makes the parity test a matrix rather than a pair.

### Effort

Roughly half a day for the extraction and the German pass, plus a couple of
hours to extend the parity test to JSON. It is mechanical work, and the size is
in the count of strings, not the difficulty.

---

## 5. Users carry no brand, so "the assignees of this brand" cannot be expressed

**Found while building gap 2 in v1.7.0.**

### What exists

`Support\UserDirectory::assignable()` returns every Statamic user who may
`view leadhub`, sorted by name. That is the list the task form, the contact
form and the task filter all use.

### What is missing

A brand. The decision behind task assignment was "assignees are the CP users of
the respective brand", and there is nothing to build that on:
`goldnead/statamic-brand-context` isolates **Eloquent models** through
`Concerns\HasBrand` and a global scope, and a Statamic user is not an Eloquent
model of that kind — no `brand_id` column, no membership pivot, no per-brand
role. So in a multi-brand install every LeadHub user is offered as an assignee
in every brand.

### What is not affected

The work itself. Tasks are brand-scoped like everything else, so brand B asking
for a user's tasks is never shown brand A's — including `?mine=1`. That half is
asserted in `tests/Feature/CrmCrudBrandIsolationTest.php`, together with a test
that pins the current, unscoped assignee list so this decision cannot drift
silently.

### Where it belongs

Not here. A user-to-brand membership is a `statamic-brand-context` concern, and
building a LeadHub-local version of it would mean every sibling addon inventing
its own. The smallest honest options, in order of cost:

1. A permission per brand (`view leadhub crm-b`) — no schema, but the
   permission list grows with the brand list and roles become unreadable.
2. A `brand_user` pivot in brand-context plus a `UserDirectory` filter — the
   real answer, and the one that would also fix owner selection on contacts,
   which has the same hole and has had it since 1.0.

### Effort

Half a day in LeadHub once brand-context offers the membership. Everything
before that is a decision in brand-context, not work here.

---

## 6. Task assignment writes no history

**Found while building gap 2 in v1.7.0.**

### What exists

Contact assignment records a timeline entry (`TimelineService::recordAssigned`)
and notifies (`LeadHubNotifier::assigned()`, gated on `features.notifications`).

### What is missing

The same for tasks. Reassigning a task from the CP changes `assignee_id` and
nothing else: no timeline entry, no event, no notification. "Who gave me this,
and when" is not answerable.

This was left out of v1.7.0 on purpose. It needs a new `Event::TYPE_*` constant
and a matching webhook-manager trigger, which is a change to this addon's
**public surface** — the webhook bridge maps every trigger to a LeadHub event
class, and `WebhookManagerBridgeTest` enforces that mapping. A UI release is
the wrong place for that.

### Files that would be touched

- `src/Models/Event.php` — a `TYPE_TASK_ASSIGNED` constant.
- `src/Services/TimelineService.php` — `recordTaskAssigned()`.
- `resources/lang/en/timeline.php` **and** `resources/lang/de/timeline.php` —
  the summary line in both, or `TranslationParityTest` fails.
- `src/Events/` — a `LeadHubTaskAssigned` event, plus the webhook-manager
  trigger map.
- `src/Http/Controllers/Cp/TaskController::update()` — the comparison is already
  in place; only the recording is missing.
- Optionally `src/Notifications/` — contacts have `LeadAssignedNotification`,
  tasks have no equivalent, and the follow-up digest would become a candidate
  for including tasks.

### Effort

About a day, of which the UI is the smallest part.

---

## 7. The free-text company and the linked company records still do not converge

**Restated in v1.7.0.** `leadhub_contacts.company` is a string off the form;
`linkedCompanies` are `Company` records. v1.5.0 made the difference visible on
the contact page and v1.7.0 added a way to create the record — but nothing
connects the two. A contact whose form said "Muster GmbH" still has no link to
the `Muster GmbH` record sitting one screen away, and creating that record from
the contact page does not link it either.

The obvious next step is a "promote this text to a company record" action on the
contact page: resolve through `Services\CompanyResolver::resolveOrCreate()`
(which already deduplicates by normalized name and derived domain), then
`link()`. What has to be decided first is what happens to the string afterwards
— cleared, kept as the original wording, or kept and allowed to drift.

**Effort.** Half a day, most of it in deciding the above.

---

## 8. A task cannot be attached to an opportunity from the CP

**Found while proving out gap 1 in the browser (v1.7.0 QA run).**

`leadhub_tasks.opportunity_id` is a real column with a `Task::opportunity()`
relation, and `OpportunityController::destroy()` refuses to delete a deal while
tasks still point at it. Nothing in the Control Panel can set it: the task form
offers a contact and nothing else, and the opportunity form has no task list.
The column can only be written through the facade or a source projector.

The consequence is visible in the QA evidence: the screenshot proving the
opportunity delete lock had to have its blocking task created on the console,
because there is no way to make one through the interface. A refusal a user
cannot reach through the UI is a refusal they also cannot resolve through it.

**Where.** An opportunity picker on `resources/js/pages/Tasks/Create.vue` and
`Edit.vue` (options scoped to the selected contact, otherwise the list is
meaningless), `opportunity_id` in `StoreTaskRequest`/`UpdateTaskRequest`
validated through the model like every other reference, and a task panel on
`Pipelines/OpportunityEdit.vue`.

**Effort.** Half a day. The picker is the same shape as the contact picker.

---

## 9. Three indexes sit above half the InnoDB key limit

**What is wrong with them.** Nothing yet, and that is the point. v1.8.0 brought
`tests/Unit/IndexKeyLengthTest.php` over from `statamic-notifications` v1.0.4,
which compiles the migrations through Laravel's MySQL grammar and measures every
index without a server. Three existing indexes measure over half of InnoDB's
3072-byte limit:

| Index | Table | Columns | Bytes |
|---|---|---|---|
| `leadhub_events_source_type_source_id_index` | `leadhub_events` | `source_type`, `source_id` | 2040 |
| `leadhub_opportunities_source_type_source_id_index` | `leadhub_opportunities` | `source_type`, `source_id` | 2040 |
| `leadhub_tasks_assignee_id_status_due_at_index` | `leadhub_tasks` | `assignee_id`, `status`, `due_at` | 2048 |

All three are legal. The concern is the next column: one more `varchar(255)`
puts them at ~3060 of 3072, and the one after that is the migration failing on
MySQL with SQLSTATE 1071 — which is exactly how notifications v1.0.3 went down,
on a suite that was green because SQLite has no key limit at all.

**Why it is not fixed here.** Narrowing them means `->change()` on columns of
live tables, which needs its own migration, its own compatibility check against
existing data, and its own release. Smuggling it into a scoring feature would
put a schema change nobody asked for into a release about something else.

**How it is held in the meantime.** The test pins each of the three at its
measured width rather than exempting it, so widening one fails immediately and a
*new* index over half the limit fails outright. See `LEADHUB_WIDE_INDEXES` in
that file.

**What the fix looks like.** `source_type` and `assignee_id` are handles and
ids, not prose: `varchar(64)` would take the first two indexes to 520 bytes and
the third to 296. `status` on tasks is a small enum-like set and could be
`varchar(32)`.

**Effort.** Half a day including the data check. Position 12 of the Hub register.

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

Note for gap 4 above: every CRM screen brings new strings with it, and the
parity test means an untranslated one fails the suite. Write the German
counterpart in the same commit as the English original. The v1.7.0 screens
follow that rule for the PHP layer; their Vue headings and buttons are English
in every locale like all the others, which is exactly gap 4.
