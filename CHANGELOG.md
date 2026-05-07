# Changelog

All notable changes to `goldnead/statamic-leadhub` are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

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
