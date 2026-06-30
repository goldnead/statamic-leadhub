# LeadHub → CRM-Core: Implementierungsplan

> **Ziel:** LeadHub so erweitern, dass es — in Kombination mit
> `goldnead/statamic-automations` und `goldnead/statamic-webhook-manager` — das
> selbstgebaute CRM in **adriangoldner.com** (`app/…/Crm`, 13 Tabellen, ~14
> Services, 11 Observer) vollständig ablösen kann. LeadHub wird dabei vom
> reinen „Lead-Layer" zum **CRM-Kern** ausgebaut (Companies, Pipelines,
> Opportunities, Tasks, Merge, Scoring), während Orchestrierung und Transport
> in den beiden anderen Addons liegen bleiben.

Dieses Dokument ist ein **Plan**, kein Code. Es beschreibt Scope, Datenmodell,
öffentliche API, Phasen und den Migrationsweg.

---

## 1. Leitplanken

1. **Rückwärtskompatibel.** Bestehende Installationen (Form → Contact → Timeline)
   dürfen nicht brechen. Alles Neue kommt additiv und hinter Feature-Flags.
2. **Dual-Driver-Realität.** LeadHub hat zwei Storage-Driver (`eloquent` +
   `flat-file`) hinter Repository-Contracts. Relationale Tiefe (Pipelines,
   Opportunities, Stage-Historie, Tasks) ist im Flat-File-Driver unrealistisch.
   → Das **CRM-Modul setzt den `eloquent`-Driver voraus** (so wie der `SyncLog`
   heute schon nur dort existiert). Im Flat-File-Modus werden die neuen CP-Seiten
   ausgeblendet und die API wirft eine klare „requires eloquent driver"-Exception.
3. **Optional & modular.** Neues Verhalten hinter Config-Flags
   (`features.companies`, `features.pipelines`, `features.tasks`,
   `features.scoring`, `features.merge`, `features.ingestion_api`). Wer LeadHub
   nur als Lead-Capture will, merkt von alledem nichts.
4. **Drei-Addon-Rollenverteilung** (verbindlich):
   - **LeadHub** = Daten-/Domänenkern + öffentliche API.
   - **Automations** = Regeln/Workflows (ersetzt `CrmAutomationExecutor`).
   - **Webhook Manager** = Transport in/out (ersetzt `CrmWebhookTarget` +
     Thrivecart/Cal.com-Inbound).
5. **App-spezifisches bleibt in der App.** Entitlements, Bookings, Käufe,
   Stimmanalyse-Flows bleiben in adriangoldner.com. Sie speisen LeadHub nur über
   die neue **Ingestion-API** (siehe Phase 0).

---

## 2. Gap-Übersicht (Site-CRM → LeadHub)

| Site-CRM | LeadHub heute | Maßnahme |
|---|---|---|
| `CrmContact` (scoring, last_seen, do_not_contact, metadata, user_id, merge, phone-dedup) | Contact (Email-Dedup, UTM, consent) | **Phase 1** Felder + Merge |
| `CrmCompany` + Contact↔Company | nur `company`-String | **Phase 2** |
| `CrmPipeline/Stage/Opportunity/StageTransition` | nur linearer `status` | **Phase 4** |
| `CrmTask` (mehrere, Priorität, assignee) | 1 aktiver `Followup` | **Phase 3** |
| `CrmTimelineEvent` (polymorph, dedupe_key, occurred_at) | `Event` (nur Contact-gebunden) | **Phase 0** |
| 11 Ingestion-Quellen via Projector | nur `SubmissionCreated` | **Phase 0** |
| `CrmAutomationRule/Run` | — | Automations-Addon (**Phase 5**) |
| `CrmWebhookTarget` + Inbound (HMAC) | CRM-Connectors / Webhook-Dest. | Webhook Manager (**Phase 6**) |
| öffentliche API für App-Code | nur Container-Services, **keine Facade** | **Phase 0 (Blocker)** |

---

## 3. Phasenplan

### Phase 0 — Fundament (BLOCKER, zuerst)

Ohne diese zwei Dinge können die anderen beiden Addons nicht an LeadHub andocken.

#### 0a. Öffentliche Facade / API-Service

Der `LeadHubAdapter` in **Automations** sucht heute
`Goldnead\LeadHub\Facades\LeadHub` mit **statischen** Methoden — diese Klasse
existiert nicht, daher läuft jede schreibende LeadHub-Action aus einem Flow ins
Leere („LeadHub not installed"). Wir liefern genau diese Oberfläche:

```php
// Goldnead\Leadhub\Facades\LeadHub  (Laravel-Facade auf einen LeadHubManager)
LeadHub::statuses(): array;
LeadHub::tags(): array;
LeadHub::find(string|int $id): ?array;
LeadHub::findByEmail(string $email): ?array;
LeadHub::create(array $attributes): array;          // upsert-fähig per email
LeadHub::update(string|int $id, array $attributes): array;
LeadHub::addTag(string|int $id, string $tag): array;
LeadHub::removeTag(string|int $id, string $tag): array;
LeadHub::changeStatus(string|int $id, string $status): array;
LeadHub::addNote(string|int $id, string $body): array;
LeadHub::createFollowUp(string|int $id, array $data): array;
LeadHub::completeFollowUp(string|int $id, string|int $followUpId): array;
```

- Rückgaben als normalisierte Arrays (Adapter ruft `toArray()`/Property-Access).
- Delegiert intern an die bestehenden Services (`ContactResolver`,
  `TagService`, `FollowupService`, …) — keine Logik-Duplikation.
- Config `automations.integrations.leadhub.facade` zeigt bereits hierauf; nach
  Bereitstellung greift die Auto-Detection automatisch.

#### 0b. Generische Ingestion-API + Timeline-Erweiterung

Heute entstehen Kontakte nur aus `SubmissionCreated`. Das Site-CRM lebt von
**Multi-Source-Ingestion** (Käufe, Buchungen, Logins, Downloads, Newsletter,
Inbound-Webhooks) über das Projector-Pattern. LeadHub bekommt dieselbe Fähigkeit:

```php
// Eine Quelle (App-Code, Webhook-Handler, beliebiges Domain-Event) meldet:
LeadHub::ingest(new SourceEvent(
    email:       'kunde@example.com',
    type:        'purchase.completed',      // → Timeline-Event-Typ
    source_type: \App\Models\WebhookEvent::class,
    source_id:   $event->id,
    occurred_at: $event->received_at,
    dedupe_key:  'thrivecart:'.$event->idempotency_key, // Idempotenz!
    payload:     [...],                      // wird redacted gespeichert
    contact:     ['first_name' => ..., 'phone' => ...], // optionale Upsert-Felder
    tags:        ['kunde'],
));
```

- Intern: `ContactResolver` (Upsert by email/phone) → `TimelineService.record(...)`
  mit den neuen Feldern → Event `LeadHubSourceIngested` (für Automations/Webhooks).
- **Source-Projector-Registry** (analog `CrmSourceProjectorRegistry`): Pakete
  können `LeadHub::registerSourceProjector($projector)` registrieren, damit ein
  Eloquent-Model automatisch projiziert wird (optionaler Komfort über die
  imperative `ingest()`-API hinaus).

**Migration `add_source_fields_to_leadhub_events`:**

| Spalte | Typ | Zweck |
|---|---|---|
| `source_type` | string, nullable, index | polymorpher Ursprung |
| `source_id` | string/unsignedBigInt, nullable | " |
| `dedupe_key` | string, nullable, **unique** | verhindert Doppel-Events bei Retry |
| `occurred_at` | timestamp, nullable, index | echtes Ereignis-Datum (≠ created_at) |

> Ohne `dedupe_key` erzeugt jedes Re-Processing/Retry doppelte Timeline-Einträge
> — das ist im Site-CRM bewusst gelöst und muss hier nachgezogen werden.

---

### Phase 1 — Contact-Erweiterungen + Merge

**Migration `add_crm_fields_to_leadhub_contacts`:**

| Spalte | Typ | Quelle (Site) |
|---|---|---|
| `phone_normalized` | string, nullable, index | Phone-Dedup |
| `engagement_score` | integer, default 0, index | Lead-Scoring |
| `last_seen_at` | timestamp, nullable | Aktivität |
| `do_not_contact` | boolean, default false, index | Opt-out/Compliance |
| `metadata_json` | json, nullable | Custom Fields |
| `user_id` | FK users, nullable | Kontakt ↔ App-User |
| `merged_into_contact_id` | self-FK, nullable | Merge |

- **`ContactResolver`** erweitern: Dedup zusätzlich per `phone_normalized`;
  `PhoneNormalizer` analog zum bestehenden `EmailNormalizer`.
- **`ContactMergeService`** (neu, Vorbild Site): `merge(Contact $loser, Contact $winner)`
  — verschiebt Events/Notes/Followups/Tags/(Tasks/Opportunities), setzt
  `merged_into_contact_id`, feuert `LeadHubContactsMerged`. Scope `unmerged()`.
- **Scoring (Phase 1b):** `engagement_score` über konfigurierbare Event→Punkte-Map
  bei jedem Timeline-Event fortschreiben; reines Eloquent, kein UI-Zwang.
- CP: ContactDetail um Scoring/last_seen/do-not-contact/Merge-Button erweitern.

---

### Phase 2 — Companies

**Migrationen:** `leadhub_companies`, `leadhub_contact_company` (Pivot mit
`relationship_label`, `is_primary`).

| `leadhub_companies` | Typ |
|---|---|
| `uuid` | string unique |
| `name`, `name_normalized` (index) | string |
| `website`, `domain` | string nullable |
| `industry`, `employee_range`, `description` | nullable |
| `status`, `owner_id` (FK users), `metadata_json` | |

- Modelle `Company`, Pivot; `Contact::companies()` ↔ `Company::contacts()`.
- Repository-Contract `CompanyRepository` (nur Eloquent-Impl; Flat-File wirft
  „not supported").
- Auto-Domain-Ableitung aus `website` (wie Site), Dedup über `name_normalized`.
- CP-Seiten `Companies/Index.vue`, `Companies/Show.vue` (Kontakte, Notizen,
  Tasks, Opportunities der Firma).

---

### Phase 3 — Tasks (erweitert das Followup-Konzept)

Heute: **ein** aktiver `Followup` pro Kontakt. Ziel: vollwertige Tasks
(mehrere, Priorität, assignee, Fälligkeit, optional an Opportunity).

**Migration `leadhub_tasks`** (gemappt auf `crm_tasks`):
`uuid, contact_id (nullable), opportunity_id (nullable), title, description,
status (open/done…), priority, due_at, completed_at, assignee_id, created_by,
completed_by, metadata_json`.

- **Strategie:** `Followup` bleibt als „nächste Aktion"-Shortcut bestehen
  (rückwärtskompatibel), wird intern aber als spezieller Task modelliert ODER der
  bestehende `FollowupService` bekommt eine Task-Sicht. Entscheidung in Phase 3
  (siehe §7 Offene Punkte).
- Neue Events `LeadHubTaskCreated/Completed`; Digest erweitern (Tasks due/overdue).
- CP: `Tasks/Index.vue` (Filter assignee/status/due), Task-Block im ContactDetail.

---

### Phase 4 — Pipelines / Stages / Opportunities (das CRM-Herzstück)

**Migrationen** (gemappt auf `crm_pipelines/stages/opportunities/stage_transitions`):

- `leadhub_pipelines`: `name, slug (unique), description, sort_order, is_active`.
- `leadhub_stages`: `pipeline_id, name, slug, sort_order, is_terminal,
  terminal_outcome (won/lost), description` — unique `(pipeline_id, slug)`.
- `leadhub_opportunities`: `uuid, contact_id, company_id (nullable), pipeline_id,
  stage_id, title, value_estimate, confidence, status (open/closed),
  outcome (won/lost), manual_override, source_type/source_id, last_activity_at,
  closed_at/won_at/lost_at, owner_id, metadata_json`.
- `leadhub_stage_transitions`: `opportunity_id, from_stage_id, to_stage_id,
  actor_id, occurred_at, note`.

- Services: `StageTransitionService` (Übergang + Historie + Terminal-Outcome),
  `PipelineProjector` (Opportunity aus Source-Event erzeugen/aktualisieren — z. B.
  Kauf → „won" in Pipeline „engagement", wie im Site-CRM).
- Repository-Contracts; nur Eloquent.
- CP: **`Pipelines/Board.vue`** (Kanban, Drag&Drop zwischen Stages),
  `Pipelines/Manage.vue` (Pipelines/Stages konfigurieren), Opportunity-Block im
  ContactDetail. Dashboard-KPIs um Pipeline-Wert/Won-Rate erweitern.
- Neue Events `LeadHubOpportunityCreated/Updated/StageChanged/Won/Lost`.

> **Architektur-Hinweis:** LeadHubs README sagt aktuell explizit „It is not a full
> CRM". Mit Phase 4 ändert sich diese Positionierung bewusst. README/MARKETPLACE
> entsprechend anpassen; Feature-Flag `features.pipelines` default `false`, damit
> Bestandsnutzer die neue Komplexität aktiv einschalten.

---

### Phase 5 — Andockung an Automations

Sobald LeadHub Tasks/Pipelines kann, fehlen in Automations die passenden Nodes
(im Site-`CrmAutomationExecutor` existieren sie bereits):

- **Neue LeadHub-Actions** (im Automations-Addon, via `LeadHubAdapter`):
  `leadhub.create_task`, `leadhub.move_stage`,
  `leadhub.create_or_update_opportunity`, `leadhub.merge_contacts`.
- **Neue LeadHub-Trigger:** `leadhub.opportunity_stage_changed`,
  `leadhub.opportunity_won` / `_lost`, `leadhub.task_due`,
  `leadhub.company_created`.
- **`leadhub.follow_up_due` reparieren:** Automations hat den Trigger schon, aber
  LeadHub feuert kein zeitbasiertes „fällig"-Event (nur den Digest). LeadHub
  bekommt einen Scheduler-Tick, der pro fälligem Followup/Task ein
  `LeadHubFollowupDue`-Event feuert → Trigger greift.
- Adapter-Methoden (`createTask`, `moveStage`, `upsertOpportunity`) ergänzen.

> Damit ersetzt der visuelle Automations-Builder den Code-basierten
> `CrmAutomationExecutor` 1:1 (Conditions/Operatoren sind bereits abgedeckt,
> Drip/Nurture via `DelayNode`).

---

### Phase 6 — Andockung an Webhook Manager

- **Inbound „Upsert Lead"-Handler:** LeadHub registriert über
  `WebhookManager::registerInboundActionHandler()` einen Handler, der eine
  gemappte Inbound-Payload via `LeadHub::ingest()` zu Contact + Timeline macht.
  Damit werden Thrivecart/Cal.com-Style-Webhooks ohne Custom-Controller zu Leads
  (HMAC-Verifizierung, Replay-Schutz, Mapping übernimmt der Webhook Manager).
- **Outbound:** Die bestehende `WebhookManagerBridge` um die neuen Events
  (Opportunity/Task/Company/Merge) erweitern, damit sie als Outbound-Trigger
  wählbar sind.
- Damit entfällt das Site-`CrmWebhookTarget` zugunsten des Webhook Managers.

---

### Phase 7 — Consent / Newsletter (optional)

- `do_not_contact` (Phase 1) als zentraler Opt-out-Schalter; alle CRM-Connectors
  respektieren ihn (kein Push bei `do_not_contact = true`).
- Brevo-Connector zweiseitig: bei `do_not_contact`/Unsubscribe Kontakt aus der
  Liste entfernen (heute nur Upsert). Optionales schlankes Topic-/Subscription-
  Modell **nur**, falls das Newsletter-Management wirklich mitwandern soll —
  sonst bleibt es App-seitig und speist via Ingestion-API.

---

## 4. Öffentliche Events (Erweiterung)

Bestehend (11) bleiben. Neu:
`LeadHubSourceIngested`, `LeadHubContactsMerged`, `LeadHubTaskCreated`,
`LeadHubTaskCompleted`, `LeadHubFollowupDue`, `LeadHubCompanyCreated`,
`LeadHubOpportunityCreated`, `LeadHubOpportunityUpdated`,
`LeadHubOpportunityStageChanged`, `LeadHubOpportunityWon`,
`LeadHubOpportunityLost`. Alle erben vom bestehenden `LeadHubEvent`-Muster und
werden automatisch in der `WebhookManagerBridge` als Trigger registriert.

---

## 5. Migrationsweg adriangoldner.com → Addons

1. Addons via Composer einbinden (Path-Repo o. Packagist), `eloquent`-Driver,
   Pipelines/Companies/Tasks-Flags an.
2. **Daten-Migration** `crm_* → leadhub_*` per einmaligem Artisan-Command
   (Spalten sind weitgehend deckungsgleich, siehe Tabellen oben). `dedupe_key`
   der Timeline 1:1 übernehmbar.
3. Die 11 Site-Observer von `ProjectSourceEventJob` (eigenes CRM) auf
   `LeadHub::ingest(...)` umstellen — Observer bleiben, Ziel ändert sich.
4. Inbound-Controller (Thrivecart/Cal.com) → Webhook-Manager-Inbound-Endpoints
   mit „Upsert Lead"-Handler ablösen (oder zunächst parallel `ingest()` rufen).
5. `CrmAutomationRule`-Logik in Automations-Flows nachbauen (oder Importer).
6. Custom-CP (`CrmController`, Vue-Seiten) gegen die LeadHub-CP tauschen, dann
   `app/…/Crm` entfernen.

> Reihenfolge erlaubt **schrittweise Ablösung**: Phase 0–1 reichen, um Ingestion
> + Timeline + Facade produktiv zu nutzen, während das alte CRM noch parallel läuft.

---

## 6. Grober Aufwand & Reihenfolge

| Phase | Inhalt | Relativer Aufwand | Abhängig von |
|---|---|---|---|
| 0 | Facade + Ingestion + Timeline-Felder | M | — |
| 1 | Contact-Felder + Merge + Scoring | M | 0 |
| 2 | Companies | M | 0 |
| 3 | Tasks | M | 0,1 |
| 4 | Pipelines/Opportunities + Kanban | **L** | 0,1 |
| 5 | Automations-Nodes + FollowupDue | M | 3,4 |
| 6 | Webhook-Manager Inbound/Outbound | S–M | 0,4 |
| 7 | Consent/Brevo-Opt-out | S | 1 |

Empfohlene Reihenfolge: **0 → 1 → 2 → 3 → 4 → 5 → 6 → 7**. Phase 0 ist
zwingend zuerst; sie schaltet sofort die schon vorhandenen Automations-/Webhook-
Manager-Fähigkeiten für LeadHub frei.

---

## 7. Offene Entscheidungen / Risiken

1. **Followup vs. Tasks (Phase 3):** Bleibt der Single-`Followup` als eigene
   Tabelle bestehen (rückwärtskompatibel) oder wird er als „primärer Task"
   modelliert? Vorschlag: Followup-Tabelle behalten, Tasks additiv — am wenigsten
   invasiv.
2. **Flat-File-Driver:** CRM-Modul wird auf `eloquent` beschränkt. Akzeptabel?
   (Site nutzt ohnehin DB.) Alternative wäre erheblicher Mehraufwand ohne Nutzen.
3. **`source_id`-Typ:** Site nutzt `unsignedBigInteger`. LeadHub-Quellen könnten
   auch String-IDs (UUID/Statamic) sein → wir nehmen `string`, um beides zu
   tragen.
4. **Positionierung:** Mit Pipelines ist LeadHub kein „lightweight lead layer"
   mehr. README/Marketing müssen das spiegeln; Flags halten den Default schlank.
5. **Scoring-Modell:** Einfache Event→Punkte-Map zuerst; komplexere Decay-/
   Zeitfenster-Logik später.
6. **Daten-Migration vs. Neustart:** Sollen historische `crm_*`-Daten migriert
   werden (Command) oder startet adriangoldner.com auf LeadHub neu? Beeinflusst
   Phase-5-Aufwand.

---

## 8. Was NICHT in LeadHub wandert

Entitlements/PackageEntitlements, Bookings, Käufe, Stimmanalyse-Flows,
Mitglieder-Portal, Circle-Importe — das bleibt App-Domäne in adriangoldner.com.
LeadHub erhält daraus nur projizierte Signale (Timeline-Events, Opportunities)
über die Ingestion-API.
