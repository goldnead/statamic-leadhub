# LeadHub for Statamic

> Turn Statamic form submissions into contacts, timelines, and follow-ups — directly inside your Control Panel.

[![tests](https://github.com/goldnead/statamic-leadhub/actions/workflows/tests.yml/badge.svg)](https://github.com/goldnead/statamic-leadhub/actions/workflows/tests.yml)
[![Statamic 6](https://img.shields.io/badge/Statamic-6.0%2B-orange.svg)](https://statamic.com)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-blue.svg)](https://www.php.net/)
[![License: Commercial](https://img.shields.io/badge/License-Commercial-blue.svg)](LICENSE)

LeadHub is a lightweight lead manager built into the Statamic Control Panel. Instead of treating every form submission as an isolated event, LeadHub automatically creates contacts, merges repeated inquiries by email, tracks a timeline of submissions and notes, and helps you follow up with the right leads at the right time.

It is **not** a full CRM. It's the missing layer between your website forms and your sales tools.

---

## What you get

- **Contacts from forms** — every Statamic form submission becomes a contact, deduplicated by email
- **Timeline per contact** — submissions, notes, status changes, tag changes and follow-ups in one chronological view
- **Lead status workflow** — `New → Contacted → Qualified → Won / Lost`, with full history
- **Follow-ups** — set a single next action per contact; surface what's due today and what's overdue
- **Tags** — manual or rule-driven segmentation
- **Per-form mapping** — toggle LeadHub per form, map each form's fields to contact fields
- **Filterable list + CSV export** — find leads fast, export filtered subsets
- **Dashboard** — KPIs, latest activity, due/overdue follow-ups
- **Settings in the Control Panel** — 28 of the keys in `config/leadhub.php` are editable under **LeadHub → Settings** (`manage leadhub settings`), stored as the difference to the file. See [Settings in the Control Panel](#settings-in-the-control-panel)
- **Lead assignment + notifications** — assign an owner to each lead; e-mail your team on new leads, assignments, and a daily follow-up digest
- **Marketing attribution** — capture UTM parameters, referrer and landing page on the originating submission
- **CRM connectors** — push contacts to HubSpot, Brevo or any webhook (Zapier / Make / n8n) on create, update or status change, with a per-attempt **Sync log**. Opted-out contacts (`do_not_contact`) are never pushed.
- **Outbound events** — 26 domain events covering the contact lifecycle, follow-ups, segments and the CRM-core modules, ready for [goldnead/statamic-webhook-manager](#webhooks--outbound-integrations) or your own listeners

### CRM-core modules (opt-in)

LeadHub can grow from a lead-capture layer into a lightweight CRM. These modules are **off by default** and require the eloquent driver — enable them under `features` in `config/leadhub.php`:

- **Generic ingestion API** (`features.ingestion`) — `LeadHub::ingest()` turns *any* source (purchases, bookings, logins, inbound webhooks) into contacts + timeline entries, deduplicated by email/phone and idempotent via a `dedupe_key`. Register a `SourceProjector` to auto-map your own models.
- **Companies** (`features.companies`) — B2B company records, deduplicated by domain/name, linked to contacts with a primary flag.
- **Tasks** (`features.tasks`) — multiple tasks per contact with priority, assignee and due date (beyond the single next-action follow-up).
- **Pipelines & opportunities** (`features.pipelines`) — multi-pipeline deal tracking with stages, terminal won/lost outcomes, value/confidence, a **Kanban board**, a **pipeline-management** screen, and a **page per deal** carrying its stage history, the time it spent in each stage and the note behind every move. See [Deals & stage history](#deals--stage-history).
- **Contact merge** (`features.merge`) — `LeadHub::merge()` re-parents a duplicate's timeline/notes/tasks/opportunities onto a survivor.
- **Lead scoring** (`features.scoring`) — accumulate an `engagement_score` per activity type. Since v1.8.0 the score is shown on the contact and in the list (sortable, filterable by range), the point table is editable per brand under LeadHub → Scoring, and every change lands in the contact timeline.
- **Consent / opt-out** — `do_not_contact` is honoured by every CRM connector; `LeadHub::optOut()` also actively removes the contact from supported destinations (e.g. a Brevo list).
- **Public API & events** — a stable `Goldnead\Leadhub\Facades\LeadHub` facade to read and write leads and ingest external sources, plus the 26 lifecycle events listed under [Webhooks](#webhooks--outbound-integrations). [statamic-webhook-manager](#webhooks--outbound-integrations) pairs with these via LeadHub's built-in bridge; [statamic-automations](https://github.com/goldnead/statamic-automations) detects LeadHub on its side and offers these events as workflow triggers — no configuration in LeadHub required.

What it deliberately does **not** do (yet): bidirectional CRM *pull* sync. See [the roadmap](#roadmap).

---

## Requirements

- PHP **8.2+** (**8.3+** on Laravel 13)
- Statamic **6.0+** (the v0.3 CP rewrite uses Inertia + Vue 3 — Statamic 5 is no longer supported; pin to `^0.2.x` if you need it)
- Laravel **12.x / 13.x**. Laravel 11 is not supported: v11.0.0–v11.55.0 are covered by security advisories and Composer refuses the line, so there is no installable Laravel 11 for Statamic 6 to sit on.
- [`goldnead/statamic-brand-context`](https://github.com/goldnead/statamic-brand-context) **^1.6** — a hard dependency, not optional. It supplies the brand (tenant) every LeadHub record is scoped to. See [Brands](#brands--multi-tenancy).
- A SQL database (MySQL, PostgreSQL, SQLite) — only required for the eloquent driver

---

## Installation

```bash
composer require goldnead/statamic-leadhub
php artisan migrate          # only needed for the eloquent driver (default)
```

> **Not on Packagist yet.** `goldnead/statamic-brand-context` is still private, and Composer ignores the `repositories` block of a package it installs as a dependency. Until both packages are published, the command above resolves only in a project that declares the VCS repository itself:
>
> ```bash
> composer config repositories.brand-context vcs https://github.com/goldnead/statamic-brand-context.git
> composer config repositories.leadhub vcs https://github.com/goldnead/statamic-leadhub.git
> composer require goldnead/statamic-leadhub
> ```

That's it — **no front-end build step is required**. LeadHub ships its compiled Control Panel assets (Inertia + Vue 3 + Tailwind v4) under `resources/dist/`, and Statamic publishes them to your `public/vendor/` automatically on install. If you ever need to (re)publish them manually:

```bash
php artisan vendor:publish --tag=statamic-leadhub --force
```

Optional — publish the config to customize statuses, redaction rules, and feature flags:

```bash
php artisan vendor:publish --tag=leadhub-config
```

After installation, you'll see a new **LeadHub** entry in the Control Panel sidebar.

---

## Quick start

### 1. Connect your first form

1. Open **Control Panel → LeadHub → Forms**
2. Click **Configure** on the Statamic form you want to capture
3. Toggle **Enable LeadHub for this form**
4. Map the form's email field (required) and any other fields you want to capture
5. Save

The next time someone submits the form, a contact appears in **LeadHub → Contacts**.

### 2. Work the leads

- Click any contact to see the full timeline
- Add notes, change status, set follow-ups, attach tags
- Filter the list by status, source, tag, or follow-up state
- Export filtered subsets as CSV

### 3. (Optional) Customize statuses

Edit `config/leadhub.php`:

```php
'statuses' => [
    'new'        => 'New',
    'contacted'  => 'Contacted',
    'qualified'  => 'Qualified',
    'proposal'   => 'Proposal sent',   // your own
    'won'        => 'Won',
    'lost'       => 'Lost',
    'archived'   => 'Archived',
],
```

The status list stays in the file — removing a status would strand every contact sitting on it,
so it is shown read-only in the Control Panel. Which of them a new contact starts on, and most
other keys of this file, are editable under
[LeadHub → Settings](#settings-in-the-control-panel).

---

## Permissions

LeadHub registers sixteen permissions under the `LeadHub` group. Assign them to roles in
**CP → Users → Roles**.

| Permission | What it allows |
| ---------- | -------------- |
| `view leadhub` | The dashboard, the deal board, the deal detail page, the companies, tasks and scoring screens (read-only), and the sync log. The read side of the CRM-core modules rides on this one permission rather than a `view` of its own. |
| `view leadhub contacts` | The contact list, a contact's detail page and its timeline, and the follow-up list. |
| `create leadhub contacts` | Creating a contact by hand (also needs `features.manual_contacts`). |
| `edit leadhub contacts` | Editing a contact, adding a note, setting and completing a follow-up. Historically it also covered completing a task and moving a deal, and it still does — see below. |
| `delete leadhub contacts` | Deleting a contact for good. |
| `archive leadhub contacts` | Archiving and unarchiving a contact. |
| `export leadhub contacts` | The CSV export of the contact list, filters included. |
| `manage leadhub tags` | The Tags screen: creating, renaming and deleting tags. |
| `view leadhub segments` | The Segments screen, read-only, including a segment's member list. |
| `manage leadhub segments` | Creating, editing, activating and deleting segments, and the rule editor. |
| `manage leadhub companies` | Creating, editing and deleting company records. Deliberately separate from the contact permissions: "may edit a contact" and "may delete the company behind fifty contacts" are not the same authority. |
| `manage leadhub tasks` | Creating, editing, assigning, completing and deleting tasks. |
| `manage leadhub opportunities` | Creating, editing and deleting deals, and moving one to another stage. |
| `manage leadhub scoring` | Editing the per-brand point table under LeadHub → Scoring. Its own authority, because that table decides the score of every contact at once. |
| `manage leadhub form mappings` | The Forms screen: enabling LeadHub per form and mapping its fields. |
| `manage leadhub settings` | The Settings screen (see [Settings in the Control Panel](#settings-in-the-control-panel)) **and** the pipeline-management screen — creating pipelines, adding, renaming, reordering and deleting stages. |

### Moving a deal accepts either permission

`POST /pipelines/opportunities/{opportunity}/move` — the board's drag & drop and the stage-change
form on the [deal page](#deals--stage-history) — is satisfied by **either
`manage leadhub opportunities` or `edit leadhub contacts`**:

```php
if (! $this->userCan($request, 'manage leadhub opportunities')) {
    $this->authorizeOrFail($request, 'edit leadhub contacts');
}
```

Until v2.4.0 the route asked for `edit leadhub contacts` alone, which matched neither of its
neighbours: looking at the board is `view leadhub`, creating and deleting a deal is
`manage leadhub opportunities`. Narrowing it to the correct permission alone would have taken
drag & drop away, on upgrade day, from every install whose roles carry only the old one. So the
route was widened instead: nobody loses a capability, and a role set up to run the pipeline
gains the one it should have had. `TaskController::complete()` accepts the same pair, for the
same reason, since v1.7.0.

If you are cutting a new role for the pipeline, grant `manage leadhub opportunities`. The
fallback exists for the roles that already carry the old permission.

---

## Configuration overview

```php
// config/leadhub.php

'statuses'                                   // available lead statuses
'default_status'                             // status assigned to new contacts
'overwrite_existing_fields_from_submissions' // never overwrite manually edited contacts (default: false)
'store_full_submission_payload'              // attach raw submission to timeline
'timeline_payload_redaction'                 // sensitive keys redacted before storage
'exports.*'                                  // queue threshold, target disk and directory
'features.*'                                 // toggle features (attribution, crm_destinations, CRM-core modules)
'scoring.*'                                  // fallback point table (see Lead scoring)
'click_tracking.*'                           // dedupe window and ignored query parameters
'notifications.*'                            // recipient e-mails, digest time (see Lead assignment)
'attribution.fields'                         // which submission fields map to UTM / referrer / landing page
'crm.destinations'                           // HubSpot / Brevo / webhook targets (see CRM connectors)
'email_normalization.*'                      // how an address is normalised before deduplication
'storage.*'                                  // driver and, for `flat`, the content path
```

### Settings in the Control Panel

The config file is the default, not the last word. Since v2.3.0, 28 of the keys above can also
be changed under **LeadHub → Settings** (permission: `manage leadhub settings`): the behaviour
on a new submission, the payload redaction list, all thirteen feature flags, the export target
and queue threshold, the scoring fallback values, the click-tracking dedupe window, and the
notification switches.

Only the **difference** to the config file is stored, one row per changed key in
`leadhub_settings`. A value set back to what the file says deletes its row again, so
`config/leadhub.php` stays the default and a later release can still move it. An install that
never opens the screen behaves exactly as it did before the screen existed — including its
queue workers, since the overrides are applied in the service provider rather than in a CP
middleware.

If you edit the file on a server, check the screen: a stored override outranks the file, and a
key changed in both places will not do what the file says.

Not editable there, on purpose:

- **Credentials.** `crm.destinations` holds `token`, `api_key` and `secret`. A database row
  carrying one takes it out of the secret store and into every backup, and the screen refuses
  to serialize those entries to the browser at all.
- **Anything resolved from `env()`** — the storage driver and flat path, the notification
  switch, the recipient lists and the digest time. The deployment owns them, and a database row
  that silently outranks an env var is a setting that changes back on the next deploy with
  nobody touching the screen. They are shown on the screen read-only, so you can still check
  what is active.
- **`statuses` and `attribution.fields`.** A map of key to value is not a field: editing one
  means adding, renaming and removing rows, and removing a status strands every contact sitting
  on it. The statuses are printed read-only and `default_status` is offered as a select over
  them.
- **`scoring.events`.** Since v1.8.0 it is only the fallback for a brand with no rows in
  `leadhub_scoring_rules` — a number changed here would look effective and do nothing on every
  brand that has rules. `scoring.default` and `scoring.timeline` are read live and are offered.
- **`email_normalization.*`.** A data-consistency rule rather than a preference: the normalised
  address carries a unique index, and changing the rule afterwards leaves existing rows
  normalised by the old one, so deduplication quietly stops matching what it used to match.

The settings apply to the whole installation, not to one brand — unlike the scoring rules and
the segments, which are per brand.

On a **flat-driver** install, where migrations are not required, the `leadhub_settings` table
may genuinely not exist. Reading survives that (no overrides means the config file, which is
correct); writing does not, so the screen goes read-only and says why, instead of offering a
Save button that answers a SQL error. `php artisan migrate` creates the one table if you want
the screen.

---

## Lead assignment & notifications

Assign an owner to any lead and keep your team in the loop by e-mail.

- **Owner** — pick an assignee on the contact detail page. The change is recorded on the timeline. Filter the contacts list by `?assigned_to=<id>`, `?assigned_to=none`, or `?mine`.
- **Who can be picked** — the users who may `view leadhub` **and** belong to the current brand, per `goldnead/statamic-brand-context` (`Users → Brand Members`). Superusers are not exempt. A user with no membership anywhere counts as a member of every brand, so an install that has recorded no memberships — every install, until somebody records one — sees the same list it saw before. The same list backs the task assignee and the opportunity owner, and the same list is what a write is validated against.
- **Notifications** — three Laravel notifications, all opt-in:
  - **New lead** — fired when a contact is first created
  - **Lead assigned** — fired when a lead gets an owner
  - **Daily follow-up digest** — a once-a-day summary of due / overdue follow-ups
- **Task assigned** — when `goldnead/statamic-notifications` is installed, handing a task to somebody notifies them there (in-app, mail, or digest, per their preferences), and open tasks are contributed to the digest. Assigning a task to yourself notifies nobody. Switch it off with `leadhub.notifications.on_task_assignment`. Without that addon the whole path is a no-op.

Switch them on and set recipients in `config/leadhub.php` (or via env):

```php
'notifications' => [
    'enabled'    => env('LEADHUB_NOTIFICATIONS', true),  // the master switch
    'recipients' => env('LEADHUB_NOTIFY_EMAILS'),        // comma-separated team inbox(es)
    'digest' => [
        'enabled' => true,
        'time'    => env('LEADHUB_DIGEST_TIME', '08:00'), // server time, daily
    ],
],
```

`notifications.enabled`, `recipients` and `digest.time` are env-driven and stay in the file or
the environment. The individual switches (`new_lead`, `on_assignment`, `on_task_assignment`,
`digest.enabled`) are also editable under [LeadHub → Settings](#settings-in-the-control-panel).

The digest is wired into the Laravel scheduler automatically. Make sure your app runs the scheduler (`php artisan schedule:work`, or a cron entry calling `schedule:run`). You can also trigger it manually:

```bash
php artisan leadhub:followups:digest
```

> Assigning leads to different team members means more than one CP user, which requires **Statamic Pro** (`STATAMIC_PRO_ENABLED=true`).

Notifications use Laravel's mail channel, so they respect your existing `MAIL_*` config. Sending is fail-safe — a mailer error is logged and never blocks the lead pipeline.

### Who they come from (multi-brand)

Since 2.1.0 every one of these mails leaves as **the brand the contact belongs to**, not as the host. The address, the sender name, the transport and the language come from `brands.settings.mail` through `goldnead/statamic-brand-context` 1.8+:

```
settings->mail->from_address   (required once `mail` is present at all)
settings->mail->from_name      (defaults to the brand name)
settings->mail->mailer         (a mailer from config/mail.php)
settings->mail->locale         (the language its mail is written in)
```

Why it matters even for internal mail: one relay account verifies one set of sending domains. Send brand A's alert through brand B's account and the provider rejects the address or silently rewrites it. Nobody outside sees the wrong name — what they see is a lead that was never followed up, because the alert never arrived.

- A brand that declares **nothing** under `settings.mail` sends exactly as before. Every single-brand install is in this case, and it is covered by a test.
- A brand that declares `settings.mail` but **no `from_address`**, or names a mailer `config/mail.php` does not define, sends **nothing** and logs an error. Half a pair is worse than none: it puts one brand's transport behind another brand's address.
- `leadhub:followups:digest` asks before it sends and reports only what went out. A brand it cannot send for is skipped with a warning, and the other brands still get their digest.

Rebind `Goldnead\Leadhub\Contracts\SenderIdentityResolver` to answer differently for this addon alone; rebind the brand-context contract to answer for every addon that has not been rebound.

---

## Marketing attribution

When `features.attribution` is on, LeadHub captures campaign context from the originating form submission and stores it on the contact:

| Contact field   | Default submission source |
| --------------- | ------------------------- |
| `utm_source`    | `utm_source`              |
| `utm_medium`    | `utm_medium`              |
| `utm_campaign`  | `utm_campaign`            |
| `utm_term`      | `utm_term`                |
| `utm_content`   | `utm_content`             |
| `referrer`      | `referrer`                |
| `landing_page`  | `landing_page`            |

Capture works automatically as long as those values reach the submission — typically by adding hidden fields to your form populated from the query string / `document.referrer`. Remap any field name in `config/leadhub.php`:

```php
'features' => [
    'attribution' => true,
],

'attribution' => [
    'fields' => [
        'utm_source'   => 'utm_source',
        'landing_page' => 'landing_page',
        // 'utm_campaign' => 'campaign',   // ← map your own field name
    ],
],
```

The captured values appear in an **Attribution** panel on the contact detail page and are included in CRM payloads and exports.

---

## Revenue per contact

LeadHub knew what a person did and never what they paid. It does now — as a ledger any contributor
may write into, plus totals cached on the contact so a segment can compare them and a listing can
sort by them.

Nothing here knows what a product is. An amount, a currency and a stable reference is the whole of
the contract:

```php
LeadHub::ingest([...]);                       // resolve or create the contact first

LeadHub::recordRevenue(
    'kaeuferin@example.com',
    'payments:payment:41',                    // namespaced by whoever contributes it
    1900,
    'EUR',
    now(),
    'statamic-payments',
);

LeadHub::refundRevenue('payments:payment:41', 400);   // the RUNNING total, not one movement
LeadHub::revenueFor($contactId);                      // the ledger behind the totals
```

Three rules worth knowing before you contribute to it:

- **`recordRevenue()` never creates a contact.** A mis-addressed webhook must not populate the CRM
  with strangers. Contributors that legitimately create on purchase call `ingest()` first, which
  resolves or creates.
- **The reference is the idempotency**, and a unique index enforces it. The same reference twice
  returns the first entry and changes nothing, so a redelivered webhook is free.
- **A refund takes the running total**, not the movement. A delta would subtract twice on a
  redelivery and leave a lifetime value quietly too low — wrong in the direction nobody checks.

The totals (`revenue_cent`, `revenue_refunded_cent`, `purchase_count`, `first_purchase_at`,
`last_purchase_at`) are a cache, recomputed from the ledger in a single statement after every write.
When the two disagree the rows are right; `RevenueService::recalculate($contact)` repairs the cache.
All five are available to segments, so "has paid more than 100 €" is a rule and not a report.

Eloquent driver only, like deals: the flat-file store has no table to aggregate over.

## One page per person

The contact screen answers "what is going on with this person" in one place. Above the detail
sit five numbers — first contact, last contact, purchases, lifetime value per currency, active
access — and below it one timeline, newest first, that merges LeadHub's own events with what the
sibling addons know:

| Source | What it adds | Matched on |
| --- | --- | --- |
| `goldnead/statamic-payments` | purchases with their line items, pending and failed payments, refunds | `LOWER(TRIM(email))` |
| `goldnead/statamic-entitlements` | access granted / expired / revoked, with the state that is true now | subject `('email', address)` and `(Contact, id)` |
| `goldnead/statamic-booking` | appointments, dated by the appointment | `LOWER(TRIM(email))` |
| `goldnead/statamic-consent` | consent decisions | the `consent_id` in `metadata_json` or `custom_fields` — consent records carry no address, on purpose |

Each reader lives **inside LeadHub** (`src/Integrations/Timeline/`), refers to its neighbour by a
string class name and runs only when that addon is installed and migrated. Nothing is required;
an install with LeadHub alone shows its own events and the revenue ledger's totals. A reader can be
switched off under `leadhub.timeline.sources`, and `leadhub.timeline.limit` caps the merged list.

When the payments reader runs, the `payments.*` events that payments' bridge writes into
`leadhub_events` are hidden, so a purchase appears once. When it does not run, they stay — they are
then the only record.

A host or another addon can contribute a feed of its own:

```php
use Goldnead\Leadhub\Contracts\TimelineSource;
use Goldnead\Leadhub\Facades\LeadHub;

LeadHub::registerTimelineSource(new class implements TimelineSource { /* key(), available(), entries(), stats(), supersedes() */ });
```

### Grant access from the contact

With entitlements installed, the Actions panel gains **Grant access**: pick a product, add a note,
and LeadHub writes through the entitlements facade (`Entitlements::grant()`, source `manual`,
reference `leadhub:<contact uuid>`, the note and the granting user in `meta`). Idempotent per
contact and product; a revoked grant stays revoked, as the facade guarantees. The product list is
payments' catalogue when payments is installed (a bundle grants every slug it carries), otherwise
the slugs entitlements has already seen. The click is also recorded on the LeadHub timeline as
`access_granted`.

Its own permission — **Grant access (entitlements)**, `grant leadhub access` — because reading a
contact and opening a paid course for them are not the same authority. Without it the route answers
403; with it but without entitlements, 404.

## CRM connectors & sync log

Push contacts to external systems when they're **created**, **updated**, or their **status changes**. Turn the feature on, then declare one or more destinations:

```php
'features' => [
    'crm_destinations' => true,
],

'crm' => [
    'destinations' => [
        'hubspot' => [
            'driver'   => 'hubspot',
            'enabled'  => true,
            'token'    => env('LEADHUB_HUBSPOT_TOKEN'),  // private-app token
            'triggers' => ['created', 'status_changed'],
        ],
        'brevo' => [
            'driver'   => 'brevo',
            'enabled'  => true,
            'api_key'  => env('LEADHUB_BREVO_KEY'),
            'list_id'  => env('LEADHUB_BREVO_LIST'),      // optional
        ],
        'zapier' => [
            'driver'   => 'webhook',
            'enabled'  => true,
            'url'      => env('LEADHUB_WEBHOOK_URL'),
            'secret'   => env('LEADHUB_WEBHOOK_SECRET'),  // optional HMAC signing
        ],
    ],
],
```

**Built-in drivers**

- **`hubspot`** — upserts a contact via the HubSpot CRM v3 API (creates, or patches the existing contact on a 409 conflict).
- **`brevo`** — upserts a contact via the Brevo (Sendinblue) API, optionally adding it to a list.
- **`webhook`** — POSTs the normalized contact as JSON to any URL (Zapier, Make, n8n, or a webhook addon). When a `secret` is set, the body is signed and sent as `X-LeadHub-Signature: sha256=<hmac>`.

**`triggers`** controls which lifecycle events a destination listens for — any of `created`, `updated`, `status_changed`. Omit it to listen for all three.

**Custom drivers.** Register your own destination from a service provider:

```php
use Goldnead\Leadhub\Crm\DestinationManager;

app(DestinationManager::class)->extend('salesforce', function (string $key, array $config) {
    return new \App\Leadhub\SalesforceDestination($key, $config);
});
```

Each destination implements `Goldnead\Leadhub\Contracts\CrmDestination` (`driver(): string` and `push(Contact): SyncResult`).

**Sync log.** Every attempt runs on the queue and is recorded twice: once on the contact's timeline, and once in a dedicated log surfaced under **LeadHub → Sync log** (contact, destination, event, status, HTTP code, message, timestamp). Failed jobs retry with backoff. On the flat-file driver the dedicated log table is skipped gracefully — the timeline entry is still written.

> Syncs are queued, so configure a real queue worker (`QUEUE_CONNECTION` ≠ `sync`) in production for non-blocking pushes.

---

## Brands & multi-tenancy

Every LeadHub record belongs to a **brand**. Brands come from
[`goldnead/statamic-brand-context`](https://github.com/goldnead/statamic-brand-context),
which is why that package is a hard `require` and not a suggestion: without it there is
no tenant to scope a contact to.

What that means in practice:

- **Reads and writes are scoped to the current brand.** A contact created while brand A is
  active is invisible to brand B — including in the listings, the dashboard counts, the
  segments and the exports.
- **Five identifiers are unique per brand rather than globally** (see
  [Architecture](#architecture)), so the same e-mail address can exist as a separate
  contact in two brands.
- **Under the `flat` driver the brand lives in the path**, not in the file:
  `content/leadhub/{brand}/contacts/{uuid}.yaml`. The JSON index is per brand too, and it
  is invalidated in-process when the active brand changes.
- **The scheduled commands sweep every brand.** `leadhub:storage:migrate` deliberately
  does not: it requires `--brand`, because iterating brands there would merge contacts
  across tenants.
- **Upgrading from the pre-brand layout** is `php artisan leadhub:migrate-flat-brands`.
  It only moves files, never overwrites, and a second run is a no-op.

### Statamic sites

LeadHub has **no notion of Statamic sites**. A multi-site install does not get one contact
pool per site out of the box — separation is done with brands instead. If your sites map
onto tenants, model them as brands; if they do not, all sites share one pool.

---

## Architecture

LeadHub ships with **two storage drivers**. Choose the one that fits your project:

### `eloquent` (default)

Dedicated database tables. The core: `leadhub_contacts`, `leadhub_events`, `leadhub_notes`,
`leadhub_tags`, `leadhub_contact_tag`, `leadhub_followups`, `leadhub_form_mappings`,
`leadhub_sync_logs`, `leadhub_settings`. The opt-in modules add
`leadhub_companies`, `leadhub_contact_company`, `leadhub_tasks`, `leadhub_pipelines`,
`leadhub_stages`, `leadhub_opportunities`, `leadhub_stage_transitions`,
`leadhub_scoring_rules`, `leadhub_segments` and `leadhub_segment_contact`.

- Best for any project with **>500 contacts** or **>10k timeline events**
- Performant filtering, sorting, full-text search
- Required for queued exports past the threshold
- Standard Laravel migrations (`php artisan migrate`)

Five identifiers are unique per brand rather than globally: a contact's
normalised email address, a tag slug, a pipeline slug, an event `dedupe_key`, a
form mapping's `form_handle` and a segment handle. To check that the database is
actually enforcing that — which is not the same question as whether the
migrations ran:

```bash
php artisan leadhub:brand-integrity            # reports; changes nothing
php artisan leadhub:brand-integrity --repair   # rebuilds the indexes only
```

It prints every colliding row it finds and never deletes one. See the 1.10.1
entry in the CHANGELOG for when you would need it.

### `flat` (Statamic-native)

Stores leads as YAML files under `content/leadhub/`, with a Stache-style JSON index for fast lookups.

```
content/leadhub/
├── contacts/
│   └── {uuid}.yaml          # 1 file per contact (notes embedded, tag_ids inline)
├── events/
│   └── {uuid}.jsonl         # append-only timeline log per contact
├── followups/
│   └── {uuid}.jsonl         # append-only follow-up history per contact
├── tags.yaml                # all tags
└── form-mappings.yaml       # all form mappings

storage/app/leadhub/index/   # JSON indexes — auto-rebuilt on file mtime drift
├── contacts.json
├── tags.json
└── form_mappings.json
```

- True to Statamic's flat-file ethos
- Git-versionable lead data
- Zero database required
- **Best for ≤500 contacts and ≤10k timeline events** — beyond that, performance suffers
- Switch on with `LEADHUB_DRIVER=flat`

### Switching drivers

You can move existing data between drivers without losing anything:

```bash
# Migrate from database tables to YAML files:
php artisan leadhub:storage:migrate --from=eloquent --to=flat

# Or back the other way:
php artisan leadhub:storage:migrate --from=flat --to=eloquent

# Dry-run first to see what would move:
php artisan leadhub:storage:migrate --from=eloquent --to=flat --dry-run
```

After switching, set `LEADHUB_DRIVER=flat` (or `=eloquent`) in your `.env` and clear caches.

If you ever edit the flat-file YAML by hand, rebuild the indexes:

```bash
php artisan leadhub:stache:warm
php artisan leadhub:stache:warm --clear   # full rebuild
```

The original Statamic form submissions remain untouched — LeadHub stores only references and a redacted payload copy, regardless of driver.

### How a submission becomes a contact

```
Statamic form submission
    └── SubmissionCreated event
        └── CreateOrUpdateLeadFromSubmission listener
            ├── Look up form mapping (skip if missing/disabled)
            ├── SubmissionMapper → ContactDto
            ├── ContactResolver → find by email_normalized OR create new
            ├── TimelineService → record submission_received event
            ├── TagService → attach mapped + default tags
            └── Fires LeadHubContactCreated / LeadHubSubmissionAttached
                (→ notifications, CRM sync, webhooks, your listeners)
```

The listener is **fail-safe**: any exception is caught and logged. A LeadHub error never breaks the original form submission flow.

---

## Deals & stage history

Enable `features.pipelines`. Alongside the Kanban board, every opportunity has a page of its
own:

```
/cp/leadhub/pipelines/opportunities/{opportunity}
```

Reading it is `view leadhub`, the same authority as the board it sits on. Every action offered
on it is `manage leadhub opportunities` (or, for the stage change, [either
permission](#moving-a-deal-accepts-either-permission)), and the permissions travel to the page
as props, so it never draws a button that would answer 403.

The page carries four things:

- **The deal itself** — title, the contact as a **link** to their LeadHub page, company,
  pipeline, current stage, value, confidence, owner, and the timestamps (created, last
  activity, closed). `won_at` / `lost_at` are shown only where the deal's outcome agrees with
  them; see [below](#upgrading-won_at--lost_at).
- **A stage change, with a note.** The form posts to the same endpoint as the board's drag &
  drop, because that endpoint is the only one that writes the note.
- **The history**, newest first.
- **The tasks on this deal**, open work first, completed ones included — because that is what
  the deletion rule counts, and a panel filtered to open tasks would produce the one screen it
  exists to prevent: an empty list beside "this opportunity still has 3 tasks".

### The note is the only record of *why*

`leadhub_stage_transitions` has been written since the pipelines module shipped — one row per
move, with the note — and until v2.4.0 nothing read it. The contact timeline records that a
stage change happened and carries the ids; the note is not in it. The stage-change form on this
page (and the board's drag & drop, which has no note field) is what writes that row, so the
note is the only thing that will tell a later reader why the deal moved. Maximum 2000
characters, rendered ungrouped in the history.

Changing to the stage the deal is already on writes nothing. The page prevents it in the
browser, a second tab or a bare POST does not, and a history entry reading "Proposal →
Proposal" is exactly the noise that makes a history unreadable.

### Time in stage

Each entry shows how long the deal sat there: the gap to the next entry, and for the newest
entry the gap to now — or, on a **closed** deal, the gap to when it closed. A deal won in April
would otherwise read "115 days" at the top and grow by one every day, in the same column and
type as the real dwell times beneath it, answering a different question. Only a still-running
stretch carries the "running" marker, and the footnote under the history says which of the two
you are looking at.

A deal that was never moved has no transition row at all. Its first entry is built from
`opportunities.created_at` and is a full entry, not a gap — otherwise the most common deal on a
young install would show an empty panel, which reads as "nothing recorded" rather than "created
here, still here".

Stage ids in the history carry no foreign key, so a stage that was emptied and then deleted
leaves rows pointing at nothing. Those are labelled as removed rather than dropped. Stage names
are resolved in one query for the whole history: a deal with 30 moves costs the same six
queries as one with three.

### Upgrading: `won_at` / `lost_at`

Before v2.4.0 `StageTransitionService` set these two timestamps and never cleared them again,
while `status`, `outcome` and `closed_at` next to them were reset properly. A reopened deal
therefore carried a won date while being open, and a deal moved from won straight to lost
carried both. Nothing showed those columns, so nobody saw it — and `won_at` is precisely the
column somebody groups revenue by.

The service now writes both stamps in both branches, the applicable one to `now()` and the other
to `null`. The migration `2026_08_15_000001_repair_leadhub_opportunity_outcome_stamps` cleans up
the rows already stored: open deals lose both stamps, closed ones keep the one their `outcome`
names. **It parks the old values first**, in `metadata_json` under `repaired_outcome_stamps`, so
a report built on `won_at` can be reconciled after the fact. `down()` is deliberately empty.

If you report on `won_at` or `lost_at`, read that key before you conclude a number changed for a
business reason.

---

## Lead scoring

Enable `features.scoring`. Every scored activity adds points to the contact's `engagement_score`, which appears on the contact detail page and as a sortable, range-filterable column in the contact list. Each change writes a `score_changed` entry to the contact's timeline and fires `LeadHubContactScoreChanged` (available as the `leadhub.score.changed` webhook trigger).

### Rules live in the database, per brand

The point table is edited in the Control Panel under **LeadHub → Scoring** (`manage leadhub scoring`), and it is scoped per brand: the same activity can be worth 50 points in one brand and 3 in another. A rule is an activity type plus its points; the special type `*` is the catch-all for everything without a rule of its own. A deactivated rule behaves exactly as an absent one and falls through to the catch-all.

### Upgrading from a config-based point table

`leadhub.scoring` is still read as the fallback. **While a brand has no rules, the config decides,
exactly as before** — updating the addon changes no score. This is the same "database over
config" arrangement the [settings screen](#settings-in-the-control-panel) uses everywhere else;
what is specific to scoring is that the point table is per **brand**, while the settings apply
to the whole installation. Note that `scoring.default` and `scoring.timeline` are among the
values editable on that screen, so the fallback itself may be an override rather than the file.

Copy the config values into the table when you are ready:

```bash
php artisan leadhub:scoring:import --dry-run   # shows what it would write
php artisan leadhub:scoring:import             # writes it, once per brand
```

The command is idempotent, and it never overwrites a rule whose points differ from the config file — a rule that differs is one somebody edited in the CP. Use `--force` to overwrite deliberately, `--brand=<handle>` to restrict it.

Changing a rule affects future activity only. Scores already awarded are a running total on the contact and are not recalculated.

---

## Segments

Segments are **dynamic groups of contacts defined by rules**. Membership is materialized and kept up to date automatically: reactively when a contact changes, and via a daily sweep for time-based rules. Build them in the Control Panel under **LeadHub → Segments** with a live "matching contacts" preview.

### Rule vocabulary

A segment's rules are a boolean tree of `all` / `any` groups (groups nest):

```json
{
  "match": "all",
  "conditions": [
    { "type": "field", "field": "status", "operator": "eq", "value": "qualified" },
    { "type": "tag",   "operator": "has", "value": "vip" },
    { "type": "event", "operator": "has", "event": "purchase", "within_days": 30 },
    { "match": "any", "conditions": [
      { "type": "field", "field": "source", "operator": "eq", "value": "referral" },
      { "type": "field", "field": "utm_campaign", "operator": "contains", "value": "spring" }
    ]}
  ]
}
```

- **`field`** — any of `status`, `source`, `source_form`, `assigned_to`, `engagement_score`, `do_not_contact`, `created_at`, `last_activity_at`, `full_name`, `first_name`, `last_name`, `email`, `company`, `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`. Operators: `eq`, `neq`, `in`, `not_in`, `contains`, `starts_with`, `gt`, `gte`, `lt`, `lte`, `is_set`, `is_empty`, `is_true`, `is_false`, `before`, `after`, `within_days`, `older_than_days`.
- **`tag`** — `has` / `has_not` a tag (by id, slug, or name).
- **`event`** — `has` / `has_not` a timeline event key, optionally `within_days`.

An **empty rule set matches nobody** — express "everyone" as no segment at all.

### How membership stays fresh

- **Reactive:** a listener re-evaluates the mutated contact against every active segment on `LeadHubContactCreated/Updated`, `LeadHubStatusChanged`, `LeadHubTagAdded/Removed`, and `LeadHubSourceIngested`.
- **Scheduled sweep:** `leadhub:segments:sweep` (registered daily) re-materializes membership for time-based rules that no mutation would otherwise trigger.
- **Diffs fire events:** `LeadHubContactEnteredSegment` and `LeadHubContactLeftSegment` (both carry `segment_handle` / `segment_id` in `metadata`). These are exposed as Webhook Manager triggers (`leadhub.segment.entered` / `leadhub.segment.left`) automatically.
- **Loop protection:** a per-contact re-evaluation depth guard (`SegmentService::MAX_DEPTH = 1`) prevents infinite cascades when a consumer reacts to an enter/leave event by mutating the same contact.

### Consumer contract (public facade)

```php
use Goldnead\Leadhub\Facades\LeadHub;

LeadHub::segments();                          // [{ id, name, handle, is_active, members_count }, ...]
LeadHub::segmentMemberIds('qualified-leads'); // ['<contact-uuid>', ...] resolved LIVE from the rules
LeadHub::contactInSegment($contactOrId, 'qualified-leads'); // bool, cheap reactive check
```

`segmentMemberIds()` returns contact **UUIDs** and resolves live from the segment's rules (not the materialized pivot), so consumers always see the current set. It returns `[]` for an unknown or inactive segment. Guard optional integrations with `method_exists(LeadHub::getFacadeRoot(), 'segmentMemberIds')` so older LeadHub versions degrade gracefully.

Both storage drivers are supported: `eloquent` materializes membership in the `leadhub_segment_contact` pivot; `flat` mirrors segment handles onto each contact's YAML.

---

## Webhooks & outbound integrations

LeadHub doesn't ship its own webhook-sending UI — instead it fires a complete set of plain Laravel events across the contact lifecycle. That makes it a first-class **event source** for any webhook addon, queue, or listener you already run.

```php
// namespace Goldnead\Leadhub\Events

// Contact lifecycle
LeadHubContactCreated        LeadHubContactUpdated       LeadHubStatusChanged
LeadHubSubmissionAttached    LeadHubNoteAdded            LeadHubTagAdded
LeadHubTagRemoved            LeadHubContactArchived      LeadHubContactDeleted
LeadHubContactsMerged        LeadHubSourceIngested       LeadHubContactScoreChanged
LeadHubEmailLinkClicked

// Follow-ups
LeadHubFollowupSet           LeadHubFollowupCompleted    LeadHubFollowupDue

// Segments
LeadHubContactEnteredSegment LeadHubContactLeftSegment

// CRM-core modules
LeadHubCompanyCreated        LeadHubTaskCreated          LeadHubTaskAssigned
LeadHubTaskCompleted         LeadHubOpportunityCreated   LeadHubOpportunityStageChanged
LeadHubOpportunityWon        LeadHubOpportunityLost
```

The contact, follow-up and segment events extend `LeadHubEvent` and carry `$contact`, an
optional `$actor` (the acting user, if any) and an optional `$metadata` array. The module events
carry their own subject instead — `$company`, `$task`, `$opportunity` — with the same `$actor`
and `$metadata`. `LeadHubContactScoreChanged` carries the contact plus `$oldScore`, `$newScore`,
`$delta` and a `$reason`.

### Pairing with goldnead/statamic-webhook-manager

[goldnead/statamic-webhook-manager](https://github.com/goldnead/statamic-webhook-manager) is an event-driven outbound-webhook addon: you pick a **trigger** in the CP, point it at a URL, and it handles payload templating, auth (HMAC / bearer / basic), retries, delivery logging and replay.

**Install both addons and it just works** — no glue code. When LeadHub boots and detects the webhook manager, it registers eighteen of the events above as webhook-manager triggers:

```
leadhub.contact.created      leadhub.followup.set         leadhub.tag.added
leadhub.contact.updated      leadhub.followup.completed   leadhub.tag.removed
leadhub.status.changed       leadhub.followup.due         leadhub.contact.archived
leadhub.submission.attached  leadhub.note.added           leadhub.contact.deleted
leadhub.contacts.merged      leadhub.source.ingested      leadhub.score.changed
leadhub.task.assigned        leadhub.segment.entered      leadhub.segment.left
```

The company, opportunity and remaining task events are not bridged — their subject is not a
contact, and the bridge hands the contact over as the payload. Listen for them directly with
`Event::listen()`, as below.

Each fires a `TriggerDetected` event carrying the contact as the payload (plus `actor`, `metadata` and the event handle), so you create a webhook in **Webhook Manager → Webhooks**, choose e.g. *"LeadHub — status changed"* as the trigger, and you're done:

```
LeadHubStatusChanged ─► LeadHub bridge ─► WebhookManager::registerTrigger
                                          + TriggerDetected ─► your endpoint / Zapier / Make
```

The bridge is wrapped fail-safe — a webhook-manager error is logged and never breaks the LeadHub pipeline. Opt out any time with `'features' => ['webhook_manager' => false]` in `config/leadhub.php`. Under the hood it lives in `src/Integrations/WebhookManager/` and only loads the addon's classes once they're present, so LeadHub never depends on the webhook manager.

> **If you don't run a separate webhook addon**, LeadHub's built-in [`webhook` CRM driver](#crm-connectors--sync-log) covers the common case directly — an HMAC-signed JSON POST on create / update / status change, with a Sync log. Use the webhook manager when you want CP-managed routing, templating and replay across many event types; use the built-in driver when you just need contacts pushed to a URL.

### Rolling your own listener

```php
use Goldnead\Leadhub\Events\LeadHubStatusChanged;
use Illuminate\Support\Facades\Event;

Event::listen(LeadHubStatusChanged::class, function (LeadHubStatusChanged $event) {
    // $event->contact, $event->actor, $event->metadata
    MyExternalSystem::sync($event->contact);
});
```

---

## Testing

LeadHub ships with Pest unit and feature tests:

```bash
composer install
vendor/bin/pest        # or: composer test
```

The test suite uses `orchestra/testbench` with an in-memory SQLite database — no project setup required.

Code style and static analysis run from the same place:

```bash
composer lint          # vendor/bin/pint --test — checks, never fixes
composer fix           # vendor/bin/pint — applies the fixes
composer analyse       # vendor/bin/phpstan analyse (Larastan, level 5)
```

PHPStan runs against `phpstan-baseline.neon`, which freezes what `src/` already carries.
It is a ratchet for new code: shrink the baseline when you touch a file, never grow it.

### The MySQL run

SQLite has no InnoDB 3072-byte index limit, no utf8mb4 byte arithmetic, and it reports a
broken migration with a different error than MySQL does. Every migration defect this addon
has shipped was invisible under SQLite alone. Point the identical suite at a throwaway
MySQL database with:

```bash
vendor/bin/pest -c phpunit.mysql.xml
LEADHUB_DRIVER=flat vendor/bin/pest -c phpunit.mysql.xml
```

CI runs both, on both drivers.

### Building the Control Panel assets

End users never need this — the compiled assets are committed under `resources/dist/`. But if you change anything in `resources/js/` or `resources/css/`, rebuild and commit:

```bash
composer install        # provides the @statamic/cms file dependency the build needs
npm install
npm run build           # → resources/dist/build/
```

For a live dev loop against a real Statamic install, use `scripts/setup-playground.sh` (see below) and run `npm run dev` in the repo root.

### End-to-end smoke test

Pest covers the domain layer. To verify the full pipeline against a real Statamic install — auto-discovery, migrations, the `SubmissionCreated` listener, both drivers, and the `leadhub:storage:migrate` command — run the bundled smoke test:

```bash
./scripts/smoke-test.sh
```

In ~3–5 minutes the script:

1. Installs a fresh Statamic v6 project at `/tmp/leadhub-smoketest-{ts}/`
2. Wires this LeadHub repo as a Composer path repository
3. Configures SQLite, runs migrations, publishes the config
4. Creates a `contact` form (blueprint + form yaml)
5. **Eloquent driver** — submits `Form::find('contact')->makeSubmission()->save()`, asserts the contact landed in the DB
6. **Migration** — runs `php artisan leadhub:storage:migrate --from=eloquent --to=flat`, asserts YAML files appear under `content/leadhub/`
7. **Flat driver** — flips `LEADHUB_DRIVER=flat`, warms the Stache, submits a second form, asserts both contacts are visible to the flat repository

Configurable via env vars:

```bash
LEADHUB_PATH=/path/to/your/leadhub-clone   # default: parent dir of the script
TEST_DIR=/somewhere/else                    # default: /tmp/leadhub-smoketest-{ts}
STATAMIC_VERSION="^6.0"                     # default: ^6.0
PHP_BIN=/usr/local/bin/php8.3               # default: php on PATH
```

The script exits non-zero on the first failed step and leaves the broken project in place so you can `cd` in and poke around. After the run you can open the CP with:

```bash
cd /tmp/leadhub-smoketest-{ts}
php please make:user        # create yourself a CP user
php artisan serve            # then visit http://127.0.0.1:8000/cp
```

---

## Roadmap

Shipped beyond the core MVP: lead assignment + e-mail notifications, marketing attribution, CRM
connectors (HubSpot / Brevo / webhook) with a sync log, a full outbound event surface, the
CRM-core modules (companies, tasks, pipelines, scoring, segments, merge), an editable
[settings screen](#settings-in-the-control-panel) and a
[page per deal](#deals--stage-history) with its stage history.

Still on the table, not yet shipped:

- **More CRM connectors:** Pipedrive, ActiveCampaign, Salesforce (custom drivers are already supported via `DestinationManager::extend()`)
- **Bidirectional sync** — pull status / owner changes back from the CRM
- **Later:** manual contact merge UI, GDPR anonymization

Have a use case? Open an issue.

---

## Contributing

Pull requests welcome. Please:

1. Open an issue first to discuss the change
2. Add tests for new domain behavior
3. Keep PR scope tight — one concept per PR

---

## License

Commercial license, © goldnead. See [LICENSE](LICENSE).
