# LeadHub for Statamic

> Turn Statamic form submissions into contacts, timelines, and follow-ups — directly inside your Control Panel.

[![tests](https://github.com/goldnead/statamic-leadhub/actions/workflows/tests.yml/badge.svg)](https://github.com/goldnead/statamic-leadhub/actions/workflows/tests.yml)
[![Statamic 6](https://img.shields.io/badge/Statamic-6.0%2B-orange.svg)](https://statamic.com)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-blue.svg)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

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
- **Lead assignment + notifications** — assign an owner to each lead; e-mail your team on new leads, assignments, and a daily follow-up digest
- **Marketing attribution** — capture UTM parameters, referrer and landing page on the originating submission
- **CRM connectors** — push contacts to HubSpot, Brevo or any webhook (Zapier / Make / n8n) on create, update or status change, with a per-attempt **Sync log**
- **Outbound events** — eleven domain events covering the full contact lifecycle, ready for [goldnead/statamic-webhook-manager](#webhooks--outbound-integrations) or your own listeners

What it deliberately does **not** do (yet): lead scoring, manual contact merge UI, bidirectional CRM sync. See [the roadmap](#roadmap).

---

## Requirements

- PHP **8.2+**
- Statamic **6.0+** (the v0.3 CP rewrite uses Inertia + Vue 3 — Statamic 5 is no longer supported; pin to `^0.2.x` if you need it)
- Laravel **11.x / 12.x / 13.x**
- A SQL database (MySQL, PostgreSQL, SQLite) — only required for the eloquent driver

---

## Installation

```bash
composer require goldnead/statamic-leadhub
php artisan migrate          # only needed for the eloquent driver (default)
```

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

---

## Permissions

LeadHub registers granular permissions under the `LeadHub` group:

- `view leadhub`
- `view leadhub contacts`
- `create / edit / delete / archive leadhub contacts`
- `manage leadhub tags`
- `manage leadhub form mappings`
- `manage leadhub settings`
- `export leadhub contacts`

Assign them to roles in **CP → Users → Roles**.

---

## Configuration overview

```php
// config/leadhub.php

'statuses'                                   // available lead statuses
'default_status'                             // status assigned to new contacts
'overwrite_existing_fields_from_submissions' // never overwrite manually edited contacts (default: false)
'store_full_submission_payload'              // attach raw submission to timeline
'timeline_payload_redaction'                 // sensitive keys redacted before storage
'exports.queue_threshold'                    // when to push CSV exports onto the queue
'features.*'                                 // toggle features (notifications, attribution, crm_destinations)
'notifications.*'                            // recipient e-mails, digest time (see Lead assignment)
'attribution.fields'                         // which submission fields map to UTM / referrer / landing page
'crm.destinations'                           // HubSpot / Brevo / webhook targets (see CRM connectors)
```

---

## Lead assignment & notifications

Assign an owner to any lead and keep your team in the loop by e-mail.

- **Owner** — pick an assignee on the contact detail page (any user with `view leadhub`). The change is recorded on the timeline. Filter the contacts list by `?assigned_to=<id>`, `?assigned_to=none`, or `?mine`.
- **Notifications** — three Laravel notifications, all opt-in:
  - **New lead** — fired when a contact is first created
  - **Lead assigned** — fired when a lead gets an owner
  - **Daily follow-up digest** — a once-a-day summary of due / overdue follow-ups

Enable the feature and set recipients in `config/leadhub.php` (or via env):

```php
'features' => [
    'notifications' => true,
],

'notifications' => [
    'emails' => env('LEADHUB_NOTIFY_EMAILS'),   // comma-separated team inbox(es)
    'digest' => [
        'enabled' => true,
        'time'    => '08:00',                   // server time, daily
    ],
],
```

The digest is wired into the Laravel scheduler automatically. Make sure your app runs the scheduler (`php artisan schedule:work`, or a cron entry calling `schedule:run`). You can also trigger it manually:

```bash
php artisan leadhub:followups:digest
```

> Assigning leads to different team members means more than one CP user, which requires **Statamic Pro** (`STATAMIC_PRO_ENABLED=true`).

Notifications use Laravel's mail channel, so they respect your existing `MAIL_*` config. Sending is fail-safe — a mailer error is logged and never blocks the lead pipeline.

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

## Architecture

LeadHub ships with **two storage drivers**. Choose the one that fits your project:

### `eloquent` (default)

Dedicated database tables: `leadhub_contacts`, `leadhub_events`, `leadhub_notes`, `leadhub_tags`, `leadhub_contact_tag`, `leadhub_followups`, `leadhub_form_mappings`.

- Best for any project with **>500 contacts** or **>10k timeline events**
- Performant filtering, sorting, full-text search
- Required for queued exports past the threshold
- Standard Laravel migrations (`php artisan migrate`)

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

## Webhooks & outbound integrations

LeadHub doesn't ship its own webhook-sending UI — instead it fires a complete set of plain Laravel events across the contact lifecycle. That makes it a first-class **event source** for any webhook addon, queue, or listener you already run.

```php
// namespace Goldnead\Leadhub\Events
LeadHubContactCreated
LeadHubContactUpdated
LeadHubSubmissionAttached
LeadHubStatusChanged
LeadHubTagAdded
LeadHubTagRemoved
LeadHubNoteAdded
LeadHubFollowupSet
LeadHubFollowupCompleted
LeadHubContactArchived
LeadHubContactDeleted
```

Each event carries `$contact`, optional `$actor` (the acting user, if any), and optional `$metadata`.

### Pairing with goldnead/statamic-webhook-manager

[goldnead/statamic-webhook-manager](https://github.com/goldnead/statamic-webhook-manager) is an event-driven outbound-webhook addon: you map an event class to a target URL in the CP and it handles delivery, retries and logging. Because LeadHub's events are public, you point the webhook manager straight at them — no glue code, no duplicated webhook logic in LeadHub:

```
LeadHubContactCreated  ─┐
LeadHubStatusChanged   ─┼─►  webhook-manager  ─►  your endpoint / Zapier / Make
LeadHubFollowupSet     ─┘
```

Register the LeadHub events you care about in the webhook manager's configuration (it subscribes to any dispatched event class), and each will deliver the contact payload to your configured destinations.

> **If you don't run a separate webhook addon**, LeadHub's built-in [`webhook` CRM driver](#crm-connectors--sync-log) covers the common case directly — an HMAC-signed JSON POST on create / update / status change, with a Sync log. Use the webhook manager when you want CP-managed routing across many event types; use the built-in driver when you just need contacts pushed to a URL.

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
vendor/bin/pest
```

The test suite uses `orchestra/testbench` with an in-memory SQLite database — no project setup required.

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

Shipped beyond the core MVP: lead assignment + e-mail notifications, marketing attribution, CRM connectors (HubSpot / Brevo / webhook) with a sync log, and a full outbound event surface.

Still on the table, not yet shipped:

- **More CRM connectors:** Pipedrive, ActiveCampaign, Salesforce (custom drivers are already supported via `DestinationManager::extend()`)
- **Bidirectional sync** — pull status / owner changes back from the CRM
- **Later:** rule-based lead scoring, manual contact merge UI, GDPR anonymization

Have a use case? Open an issue.

---

## Contributing

Pull requests welcome. Please:

1. Open an issue first to discuss the change
2. Add tests for new domain behavior
3. Keep PR scope tight — one concept per PR

---

## License

MIT © goldnead. See [LICENSE](LICENSE).
