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

What it deliberately does **not** do (yet): webhooks, CRM connectors, UTM attribution, lead scoring, bidirectional sync. See [open questions](#roadmap).

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
'features.*'                                 // toggle MVP features
```

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
            └── Internal event: LeadHubSubmissionAttached (for future webhooks/sync)
```

The listener is **fail-safe**: any exception is caught and logged. A LeadHub error never breaks the original form submission flow.

---

## Internal events

Hook into LeadHub from your own code:

```php
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

Each event carries `$contact`, optional `$actor`, and optional `$metadata`.

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

The MVP is intentionally narrow. The architecture is prepared for, but does not yet ship:

- **Pro:** UTM attribution, webhook events, sync logs
- **CRM connectors:** HubSpot, Pipedrive, Brevo, ActiveCampaign
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
