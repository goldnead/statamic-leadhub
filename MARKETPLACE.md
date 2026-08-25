# Statamic Marketplace Copy

Source material for the Statamic Marketplace listing.

---

## Title

**LeadHub for Statamic**

## Tagline / Short Description

Turn Statamic form submissions into contacts, timelines and follow-ups directly inside your Control Panel.

## Long Description

LeadHub is a lightweight lead manager for Statamic websites. Instead of treating every form submission as an isolated event, LeadHub automatically creates contacts, merges repeated inquiries by email, tracks a timeline of submissions and notes, and helps you follow up with the right leads at the right time.

It is not a full CRM. It is the missing layer between your website forms and your sales tools.

## Positioning Sentence

Not every form submission belongs in your CRM. LeadHub helps you qualify, organize and act on website leads before syncing them elsewhere.

## Key Features

- Create contacts from Statamic form submissions
- Merge repeated inquiries by email address
- Track every submission in a contact timeline
- Add notes, tags and lead statuses
- Set simple follow-ups
- Filter and search contacts
- Export leads as CSV
- Dashboard with KPIs, due follow-ups and latest activity
- Granular CP permissions
- Internal events for future CRM sync / webhooks

## Who It's For

- Statamic agencies and freelancers building client sites
- Coaches, consultants, lawyers, agencies, schools, associations, bands, local businesses, small B2B companies
- Anyone whose form-submission volume is real but doesn't justify a HubSpot or Pipedrive seat

## Who It's *Not* For

- Teams running a full sales pipeline with deals, quotas, and forecasts
- Newsletter / email marketing
- Sales automation

## Categories

Forms · CRM · Integrations · Workflow · Utility

## Suggested Pricing Tiers

> **Not implemented.** The addon declares no `extra.statamic.editions` and contains no `Addon::edition()` check, so everything below ships to everyone. This table is a pricing proposal, not a description of the package. Nothing may be sold as tier-exclusive until the edition gate exists. A paid tier whose headline feature is already in the free one is a rejectable listing.
>
> Two rows below are already untrue of the package as it stands. UTM attribution is **on** in the default build (`config/leadhub.php`, `features.attribution => true`, read in `SubmissionMapper` and `ContactController`), so it is Core, not Pro. `features.webhooks` is read nowhere in `src/` outside the settings form — it toggles nothing.
>
> The `features.*` flags are not a licence gate and cannot become one as they stand: every one of them is a switch in the CP settings form (`src/Support/Settings.php`), so the customer flips it themselves. They separate the eloquent-driver CRM modules from the lightweight flat-file build — an architecture line, not a price line. The config comment "Pro features default to false" is a leftover from this table and does not describe the code.
>
> The family decided against editions: see `statamic-automations/CHANGELOG.md`, 2.0.0 — "There is one feature set, and entitlement is enforced by the Statamic Marketplace rather than by code in the package."

| Tier | Price | Includes |
|---|---|---|
| **LeadHub Core** | $59–99 | Everything in this MVP: contacts, timeline, status, tags, follow-ups, CSV export |
| **LeadHub Pro** | +$50 | UTM attribution, webhook events, sync logs, manual push to destination |
| **CRM Connectors** | $29 each | HubSpot, Pipedrive, Brevo, ActiveCampaign |
| **Business Bundle** | $149–199 | LeadHub + Webhook Manager + 1 selected CRM Connector |
