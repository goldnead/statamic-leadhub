# LeadHub for Statamic

> Turn Statamic form submissions into contacts, timelines, and follow-ups — directly inside your Control Panel.

[![Statamic 5+](https://img.shields.io/badge/Statamic-5.0%20%7C%206.0-orange.svg)](https://statamic.com)
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
- Statamic **5.0+** or **6.0+**
- Laravel **10.x / 11.x / 12.x**
- A SQL database (MySQL, PostgreSQL, SQLite)

---

## Installation

```bash
composer require goldnead/statamic-leadhub
php artisan migrate
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

LeadHub uses **its own database tables** (`leadhub_*`) rather than Statamic collections, because:

- timelines and filters need performant relational queries
- email-based deduplication is simpler against a real index
- future Pro features (webhooks, sync logs) need transactional shape

Tables: `leadhub_contacts`, `leadhub_events`, `leadhub_notes`, `leadhub_tags`, `leadhub_contact_tag`, `leadhub_followups`, `leadhub_form_mappings`.

The original Statamic form submissions remain untouched — LeadHub stores only references and a redacted payload copy.

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
