# Changelog

## 2.1.0 — 2026-08-13

### Fixed — staff notifications went out under the host's identity, not the brand's

`Services\LeadHubNotifier` ended in `Notification::route('mail', …)->notify()`: the
process-wide default mailer with the process-wide `mail.from`, identically for every brand.
The three notification classes carried no `from()` at all. On a host where each brand's
sending domain is verified in its own relay account (Scaleway TEM, Postmark, SES with a
verified identity) that pairs one brand's transport with another brand's address — the relay
refuses it or substitutes its own.

These are internal mails to staff, so no customer ever saw a wrong name. What a customer saw
was a lead nobody followed up, because the alert never arrived and nothing said so.

**Now every one of them leaves as the brand the contact belongs to.**
`Contracts\SenderIdentityResolver` answers "which mailer, which From, which locale for brand
N" out of `brands.settings.mail`; `Sending\BrandMailer` is the single door and puts the
answer on the message. Both inherit from `goldnead/statamic-brand-context` 1.8.0 — no fifth
copy of the rule — which is now required at `^1.8` instead of `^1.6`.

- **The brand comes from the contact row, not from the context.** A new lead is created by a
  form submission, and a queued listener or a console import may have no brand in context by
  the time the alert runs. The follow-up digest is the exception and takes the context, because
  it already runs inside `forEachBrand()`.
- **Values on the message, never state in the config.** Laravel burns `mail.from` into the
  cached mailer instance the first time a mailer name is resolved, so a scoped `Config::set`
  escapes its own `finally` and leaves the first brand's address standing for the rest of the
  process. `MailMessage::mailer()` and `MailMessage::from()` are what Laravel's own
  `MailChannel` reads.
- **A brand that declares `settings.mail` but no `from_address` — or names a mailer
  `config/mail.php` does not define — sends nothing** and is logged at error level. Half a
  pair is the failure this layer exists to prevent.
- **`leadhub:followups:digest` asks before it sends.** It used to print the number of
  recipients it had assembled; for an unconfigured brand that was a count of refusals reported
  as deliveries. It now checks the identity before the loop, skips that brand with a warning
  (exit 0, so the other brands still get their digest) and reports only what went out.
- **A notification that cannot carry an identity is refused outright.**
  `Contracts\BrandAddressed` + the `SendsAsBrand` trait are how a notification takes one, and
  `BrandMailer::notify()` throws rather than dispatch one that does not implement it. Without
  that, the next notification class somebody adds would send under the host From and nothing
  would turn red.

**No host dependency, and single-brand behaviour is unchanged.** A brand that declares nothing
under `settings.mail` resolves the config identity: no `from`, no `mailer` on the message,
`config('mail.from')` and `config('mail.default')` exactly as before. That is a test of its
own, and so is the refusal, the contact-not-context rule and the digest's honest count.

`LeadHubNotifier::newLead()`, `assigned()` and `digest()` now return `bool` (whether the mail
went out) instead of `void`. Callers that ignored the return value are unaffected.

## 2.0.0 — 2026-08-09

### Changed — the licence is now proprietary

This is a paid Marketplace addon. `composer.json` declares `proprietary` and the
licence file carries the commercial addon licence instead of MIT. Entitlement is
enforced by the Statamic Marketplace, not by code in this package.

Tags up to and including `v1.12.2` remain MIT. The change takes effect with the next
release.

## 1.12.2 — 2026-08-05

### Fixed — the breakpoint-less single-column grid utility is no longer used

Every addon in this family ships its own Tailwind build, and `@statamic/cms/tailwind.css`
routes all of them into the same `addon-utilities` layer. Media queries add no specificity, so
the bare single-column grid rule from whichever addon stylesheet loads **last** won against an
earlier addon's `sm:`/`lg:` variant and pinned that addon's grid to one column at every width.

Invisible when this addon is checked alone. It only appeared once two addons of the family were
installed together, which is the normal case on a real site.

A grid falls back to one column on its own, so the class bought nothing. The overflow guard its
`minmax(0,1fr)` track provided is preserved explicitly, because the implicit column is `auto`.

## 1.12.1 — 2026-08-04

### Fixed — the addon could not be installed on a current Statamic 6 site

`symfony/yaml` was constrained to `^6.0|^7.0`. Statamic 6.26 on Laravel 13 ships
`symfony/yaml` v8, so `composer require goldnead/statamic-leadhub` on a site created
today failed to resolve. Widened to `^6.0|^7.0|^8.0`.

This is not a speculative widening. The addon uses exactly `Yaml::parse`, `Yaml::dump`
and one `DUMP_*` constant, all unchanged across Symfony 6, 7 and 8, and the suite runs
green against v8.1.2: 479 passed, 2160 assertions.

It also blocked `goldnead/statamic-marketing`, which requires this package.

## Unreleased
### Fixed — the webhook-manager integration job tested nothing, then tested nothing loudly

`scripts/test-webhook-manager.sh` staged its throwaway copy with `git archive HEAD`.
`git archive` applies `export-ignore`, and the `.gitattributes` added in 1.12.0 marks
`/tests`, `/scripts` and `/phpunit.xml` as export-ignore so the Composer tarball ships code
only. The copy therefore had no test suite and no Pest config, and the job died on
`The test directory [%s] does not exist.` It now stages with
`git ls-files -co --exclude-standard`, which lists tracked and untracked files, honours
`.gitignore` and is not subject to `export-ignore` — the same way
`scripts/test-notifications.sh` has always done it. Uncommitted tests are now in the copy too,
so the run reflects the working tree instead of the last commit.

The script also no longer defaults to a VCS repository and `*@dev`. The addon is on Packagist,
so the default run resolves the released tag a real installation would get.
`WEBHOOK_MANAGER_PATH` (local checkout) and `WEBHOOK_MANAGER_REPO` (untagged branch) remain as
opt-in overrides.

### Fixed — with the job running again, the bridge turned out to register no triggers under test

Once the suite executed, three of the four live tests failed: webhook-manager had only its own
seven core triggers and none of LeadHub's eighteen. The bridge itself is correct — the test
harness never gave it the boot attempt a real application does.
`ServiceProvider::registerWebhookManagerBridge()` defers behind `app->booted()` because the
`webhook-manager` binding is created in the sibling's `bootAddon()`. In production Statamic's
`AppServiceProvider` runs `Statamic::runBootedCallbacks()` (which boots the addons) from its own
`app->booted()` callback, and Laravel walks that queue by index, so LeadHub's appended retry
runs afterwards and finds the binding. Testbench has no such phase: `bootAddons()` runs from
`setUp()` after the application is fully booted, so both attempts had already bailed on the
bound-check. `WebhookManagerTestCase` now performs that retry explicitly, with every one of the
bridge's own guards still in force. Production behaviour is unchanged; the suite now actually
covers the both-addons path it was written for.

### Fixed — a segment round-trip assertion that only held on SQLite

`SegmentsTest` compared a persisted rule set with `toBe()`, which is order-sensitive. MySQL's
native `json` column does not store an object verbatim: it re-emits members sorted by key
length then bytes, so `{type, field, operator, value}` reads back as
`{type, field, value, operator}` and the assertion failed on the MySQL leg while passing on
SQLite.

This is a test defect, not a data defect. Nothing is lost or coerced: `SegmentEvaluator`
addresses every member by name, neither engine reorders JSON arrays, and no code hashes or
strictly compares a rule set. Segment membership is identical on both engines. The persisted
comparison is now canonicalised (recursive `ksort`) and still strict afterwards, so type drift
— `'30'` where `30` was stored, `''` where `null` was — continues to fail. The in-memory cast
assertions stay verbatim.

Two tests were added to pin the half that is genuinely not allowed to vary: scalar types, nulls
and condition order survive persistence, and a reloaded rule set selects exactly the same
contacts as the one that was written. Both pass on SQLite (eloquent and flat) and on MySQL.

## 1.12.0 — 2026-08-01

### Security — the settings screen handed the whole config to the browser

`SettingsController` passed `config('leadhub')` wholesale as an Inertia prop. That object
carries `crm.destinations`, which is where CRM tokens and API keys live, and an Inertia prop
is rendered into the page as JSON — so anyone who could open LeadHub's settings screen, or
read the HTML of a session that had it open, could read those credentials. The controller now
passes an allowlist of the seven keys the screen actually uses. A test asserts no secret
reaches the response, and a repo-wide search found no second occurrence of the pattern.

**If you have CRM destination tokens configured, rotate them.** They were exposed to anyone
with access to that screen for as long as it has existed.

### Fixed — five deletes asked nothing before deleting

Companies (index and detail), Tasks, Pipelines and Opportunity edit deleted immediately on
click, while four other deletes in the same Control Panel asked first. All nine now use the
same confirmation modal.

### Fixed — the sync log only ever showed the newest 100 rows

It was a hand-built table with a hardcoded `limit(100)` and no way to reach anything older.
It is now a `Listing` in server mode, paginated and searchable, so a 120-row log is fully
reachable.

### Fixed — the brand-context floor was wrong

`^1.0` allowed v1.0.0, which predates `RunsForEachBrand`; installing that combination killed
the whole suite at boot. Raised to `^1.6`.

### Changed

- `Segments/Edit.vue` used `axios.post` for a preview that only reads. It is a GET now.
- `Forms/Index.vue` linked to a hardcoded `/cp/forms` instead of resolving the route.
- 12 hardcoded colours moved onto theme tokens, two hand-rebuilt headers replaced with the
  real component, and the command palette wired up on four index screens.
- `laravel/framework` narrowed to `^12.0|^13.0`. The 11.x line is withdrawn behind security
  advisories and cannot be installed, so declaring support for it was untrue rather than
  generous. `orchestra/testbench` follows to `^10.0|^11.0`.
- `tests/Feature/CpWriteRouteAuthorizationTest.php` walks the router and asserts all 38 CP
  write routes answer 403 to a user without LeadHub permissions. They already did; nothing
  held that property in place before.
- Larastan and Pint are wired in as gates; the `repositories` block, which Composer ignores in
  a dependency anyway, is gone now that brand-context resolves from Packagist.

## 1.11.0 — 2026-07-30

### Added — the flat driver isolates brands

It did not, at all. `FileStore` was a singleton bound to one path and nothing under `Repositories/FlatFile` read or wrote a brand, so `content/leadhub/contacts/` held one undifferentiated set and **every brand read every brand's contacts**.

The eloquent driver scoped correctly the whole time, which made this the sharpest kind of inconsistency: the same install isolated or did not depending on a value that reads like a storage preference.

**Brands live in the path, not in the file.**

```
content/leadhub/{brand}/contacts/{uuid}.yaml
content/leadhub/{brand}/events/{uuid}.jsonl
content/leadhub/{brand}/tags.yaml
```

A `brand:` key inside each YAML was rejected, and the reason is sharper here than anywhere else in the suite. A contact's filename is its uuid, so listing one brand's contacts by key would mean opening every other brand's file to discover it is not yours — an O(all brands) read for every query, on the driver whose whole point is that there is no database. And a missing or misspelt key falls through to the default brand, which is a leak that reads like a typo. With a directory the isolation is structural: a read never opens another brand's file, and a file in the wrong place is visible in `ls` and in a diff.

**Nothing about a single-brand install changes.** No directory appears, nothing moves, there is nothing to run. That is the overwhelming majority of flat-driver installs and they should never learn this feature exists.

**The pre-brand layout keeps working.** Under multi-brand, files still in `content/leadhub/` are read as the **default brand's** — and only the default brand's, ever. They were written before brands existed, so they belong to the brand every existing row was backfilled onto. An install that flips the flag must never open to an empty contact list.

**Fail closed.** Multi-brand with no current brand reads nothing rather than everything, matching the eloquent driver's global scope. The two drivers now agree about the one case where guessing would leak.

### Added — `leadhub:migrate-flat-brands`

Moves the pre-brand layout into a brand directory, which is what a second brand needs before it can exist without the two sharing a root.

```bash
php artisan leadhub:migrate-flat-brands --dry-run
php artisan leadhub:migrate-flat-brands
php artisan leadhub:migrate-flat-brands --brand=acme
```

It only ever **moves**. It never overwrites — a target that already exists means a finished migration or a genuine conflict, and neither is resolved by clobbering — never deletes, and a second run is a no-op. Rebuild the indexes afterwards with `leadhub:stache:warm --clear`; the command says so.

### Fixed — the index was shared across brands

`storage/app/leadhub/index/` held one set of JSON indexes. With the files correctly isolated underneath it, a shared index is the worst shape of this bug: the data on disk would be right and the answer wrong. Index paths now carry the segment, and the in-memory copy is invalidated when the brand changes — a single process switches brands whenever something wraps work in `BrandContext::runFor()`.

The staleness check also moved to `directoryMtime()`, which looks across the readable segments. Before an install has migrated, the contacts sit in the pre-brand root while the write path points at a brand directory that does not exist; `filemtime` on a missing directory returns false, so the index would never look stale and never rebuild.

### Notes

Segment resolution is memoised per brand identity. It is consulted on **every** file
operation — once per contact in a listing — and recomputing it meant a `preg_replace` each
time. The memo key carries the brand, so a `BrandContext::runFor()` switch inside one
process invalidates it rather than serving the previous brand's path. `FileStore` and every
`Index` share one instance.

`leadhub:storage:migrate` keeps its 1.10.4 guard. Migrating several brands into one flat store still merges them — the guard is about the command, not about where the files land, and it stays useful now that a per-brand target exists.

`tests/Feature/FlatDriverBrandIsolationTest.php` and `MigrateFlatBrandsCommandTest.php` cover it. Four of the five isolation cases fail without the change; the fifth is the single-brand case, which must not change and does not.

## 1.10.4 — 2026-07-30

### Fixed — `leadhub:storage:migrate` migrated nothing on a multi-brand install

It took no brand at all. A console run has no session, so the multi-brand scope failed closed and the command read an empty database:

```
 • 0 tag(s) to migrate
 • 0 contact(s) processed
Migration complete.
```

Exit code 0. On a driver migration that is worse than a crash: you set `LEADHUB_DRIVER` afterwards and the site comes up empty, having been told the move succeeded.

**The fix does not iterate brands, deliberately.** The flat driver has no brand concept — `FileStore` is a singleton on one path and nothing under `Repositories/FlatFile` reads or writes a brand, so `content/leadhub/` is one undifferentiated set. Sweeping every brand into it would merge them, and nothing in the files could tell them apart afterwards.

So the command now refuses the cases that would merge or guess:

- More than one brand and no `--brand` is **rejected**, naming the brands. Going *to* flat additionally explains that the directory holds one set, so migrate one brand at a time with `leadhub.storage.flat.path` pointed somewhere of its own.
- With `--brand` it runs inside that brand, in either direction — including to flat, which is exactly what the refusal tells you to do.
- An unknown `--brand` is rejected rather than silently falling back.
- Single-brand installs are unaffected — no option, no prompt, same behaviour.

`tests/Feature/StorageMigrateBrandGuardTest.php` covers all of it, and five of its seven cases fail without the fix. One case exists because the first draft of this guard rejected `--to=flat` outright while its own error message said to use `--brand`: an instruction the command then refuses is worse than no instruction.

> **Known limitation, now stated plainly:** the flat driver is single-brand. Making it brand-aware means resolving the current brand at call time in `FileStore` plus the index paths, and a migration for existing installs — a feature, not a bug fix.

## 1.10.3 — 2026-07-30

### Fixed — the three scheduled commands did nothing on a multi-brand install

`leadhub:segments:sweep`, `leadhub:followups:due` and `leadhub:followups:digest` did not iterate brands and took no `--brand` option. A console run has no session, so no brand is current, and the multi-brand global scope then fails closed: all three saw an empty database.

They said so in the most reassuring way available:

```
Swept 0 segment(s): 0 entered, 0 left.
Fired 0 follow-up due event(s).
No due or overdue follow-ups — nothing to send.
```

Every one of those reads as "nothing to do" and meant "I could not see anything". Exit code 0 throughout, nothing in the log.

**What it cost.** Segment membership was never re-materialised, so the CP showed **0 members** for segments whose rules clearly matched contacts, and any campaign narrowed by a segment sent to nobody. `LeadHubFollowupDue` never fired, so every automation and outbound webhook bound to that trigger stayed silent. Nobody received a follow-up digest.

**A single-brand install was never affected**, which is why this survived four releases. It was found by looking at a screenshot of the segment list.

All three now use `RunsForEachBrand` from `goldnead/statamic-brand-context` and accept `--brand=<handle|id>`. Services are resolved inside the brand context rather than injected into `handle()`, so a service that reads the current brand when it queries cannot be constructed against the wrong one. Single-brand installs are unaffected: the callback runs once, in the ambient context, with no brand switching.

`tests/Feature/ScheduledCommandsBrandSweepTest.php` covers it, including the `--brand` restriction and a structural assertion that the option exists at all — the regression is cheap to reintroduce by copying an older command.

> The trait's own docblock said this had "been found in four separate commands across three addons". These were numbers five, six and seven.

## 1.10.2 — 2026-07-30

### Fixed — all three scheduled commands were registered twice

`schedule:list` carried `leadhub:followups:digest`, `leadhub:followups:due` and `leadhub:segments:sweep` twice on every real install. The registration hung off `app->booted()`, and in a Statamic application those callbacks fire twice — something this package already knew, because the sibling bridges above are queued through a deliberate double `booted()` and survive it by being idempotent. A schedule registration is not.

Measured rather than reasoned about: `registerSchedule()` is called once, the booted callback runs twice.

**Nothing broke, and only by accident.** All three use `onOneServer()` with a fixed name, so the second copy loses the mutex and is skipped. The digest is the one that shows what that luck was worth: an entry added later without `onOneServer()` would run twice, and that is two follow-up digests to the same person on the same morning. `callAfterResolving(Schedule::class)` binds to the Schedule singleton instead, so the callback runs once however often the application announces that it has booted.

### Added — a check that can actually go red

The first version of the accompanying test passed against the unfixed provider, because Testbench fires the booted callbacks only once and never reproduced the condition. It now replays them, which is what a Statamic application does. That replay is the load-bearing part of the file: a check that cannot fail is not coverage, and this release exists because one of those had been standing in for the real thing.

It counts whatever is registered rather than asserting against today's list, so a command added later is covered without anyone remembering to come back.

All notable changes to `goldnead/statamic-leadhub` are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

_Nothing yet._

## [1.10.1] — 2026-07-30

### Fixed — updating with two contacts on one address dropped the dedupe index and did not replace it

**Affected: any install created before 1.4.0 that holds two contacts with the
same normalised email address, updating to 1.4.0 or later.** An install that has
already run `2026_07_24_100000` is untouched by this, because a migration
recorded as run never runs again — for those, only the second half below (the
nullable `brand_id`) applies.

**How to tell whether it happened to you.** Update to 1.10.1 and run:

```
php artisan leadhub:brand-integrity
```

It reads the indexes that are on the six brand-scoped LeadHub tables right now
and the rows that are in them, and says plainly whether each identifier is still
unique inside its brand. It changes nothing.

Three other fingerprints, in case the update is still in front of you rather
than behind you:

- `php artisan migrate` stopped with `SQLSTATE[23000] … UNIQUE constraint
  failed: leadhub_contacts.brand_id, leadhub_contacts.email_normalized`
  (SQLite) or `SQLSTATE[23000] … 1062 Duplicate entry` (MySQL);
- running it a second time stopped with something else entirely —
  `duplicate column name: brand_id`, or `1060 Duplicate column name
  'brand_id'` — which is the interrupted first step complaining, not the actual
  problem, and is the message most likely to send you looking in the wrong
  place;
- `select * from migrations where migration like '%add_brand_id_to_leadhub%'`
  returns nothing, while `leadhub_contacts` already has a `brand_id` column.

**What was wrong.** `2026_07_24_100000` had no guard of any kind: no
`hasTable`, no `hasColumn`, no `hasIndex`, and no duplicate check. Its third
step drops `leadhub_contacts_email_normalized_index` and then builds a unique
over `(brand_id, email_normalized)`. Before 1.4.0 that column was never unique —
two contacts with the same normalised address are ordinary data on any install
that took the same person's enquiry twice, not a corrupt state — so on such an
install the statement after the drop fails.

**What that cost.** Not the abort. The state it left behind. Neither engine
rolls DDL back, and the statement that failed came *after* the one that dropped
the index. So the update ended with `leadhub_contacts` carrying neither the old
index nor the new unique, and with the migration not recorded, so nothing in the
install knew. Form submissions kept creating contacts; they stopped being
deduplicated. `ContactResolver` looks a contact up before deciding whether to
create one, so the duplicates that follow are not immediate — they arrive
whenever two writes race, or an import runs, or anything writes the table
without going through it.

Two further defects in the same file, both of which only bite once the first one
has fired:

- **A second run died on `duplicate column name: brand_id`.** Step 1 added
  `brand_id` to seventeen tables unguarded. After an abort, some of them have it
  and some do not, and re-running re-added it. SQLite makes that half state
  permanent, because DDL is not rolled back there either.
- **The `brands` lookup came after seventeen ALTERs.** Migrating with
  `goldnead/statamic-brand-context` not yet installed threw *after* the schema
  had already been changed. It now refuses before touching anything, and says
  what to install.

**What changed.** The whole migration is re-runnable: it checks for a brand
before altering anything, adds `brand_id` only where it is missing, drops only
indexes that are actually present, does nothing at all where the wanted index is
already in place, and stops with the offending values named rather than a bare
integrity error where the rows cannot carry the index. Re-running it on a
half-migrated install finishes the update and puts the dedupe unique back.

**If duplicates are what stopped it.** They are real records of real people,
each with its own timeline, notes, tasks and opportunities, so nothing here
deletes them. `php artisan migrate` refuses and names the addresses it found;
`leadhub:brand-integrity` prints every colliding row with its id, name, status
and date. Which of them is *the* contact — and what happens to the history
hanging off the other — is a question about people, not about rows. Merge or
remove by hand, then migrate again. `leadhub:brand-integrity --repair` rebuilds
the indexes alone once nothing is in the way, and refuses while anything is.

NULL is excluded throughout, deliberately: a unique constrains no NULL on any
engine, so contacts without an address and events without a `dedupe_key` are not
collisions. On a real install they are the majority of both tables, and a check
that reported them would abort every upgrade.

### Fixed — the six brand-scoped uniques did not apply to rows without a brand

This one affects every install on 1.4.0 through 1.10.0, including the ones the
above never touched.

`brand_id` was added nullable, and all six brand-scoped uniques lead with it:
`(brand_id, email_normalized)`, `(brand_id, slug)`, `(brand_id, dedupe_key)`,
`(brand_id, form_handle)`, `(brand_id, handle)`. A SQL unique does not constrain
NULL. For any row without a `brand_id`, all five of those identifiers were
completely unconstrained — the index was present, read as an enforced rule, and
enforced nothing.

The models stamp `brand_id` on create, which is why the hole never opened in
ordinary use. It is reachable from everything that writes these tables without
going through Eloquent: a raw insert, an upsert, a CSV import, a fix run from
tinker — and, in this addon's own history, `EloquentSegmentRepository`, which
wrote `leadhub_segment_contact` with no brand at all until 1.9.0.

`2026_07_30_000001` makes the column NOT NULL on those six tables. It is
idempotent, a no-op on a fresh install, and it stamps rows that have no brand
onto the default one first. Where that would collide with a row that already has
one, the behaviour differs by column and the difference is the point: a `slug`,
a `handle` or a `form_handle` is a machine identifier, so a colliding one is
suffixed and the rename written to the log — the pattern
`goldnead/statamic-automations` 1.5.4 arrived at. An `email_normalized` or a
`dedupe_key` is not: rewriting an address is a lie about a person's record, and
rewriting a dedupe key re-opens the door for the duplicate it exists to keep
out. Those two abort and name the rows instead.

The remaining eleven tables keep a nullable, denormalised `brand_id`. None of
them constrains anything with it, and changing nullability on MySQL rebuilds the
table with `ALGORITHM=COPY`. `leadhub_events` is tightened despite being a log
that grows without bound, because its unique *is* the idempotency promise made
to every webhook and import that retries — expect that ALTER to be the long one
on a large install.

### Added — `php artisan leadhub:brand-integrity`

Reports whether the six identifiers are actually unique inside their brand. It
does not ask whether a migration ran — that is the mistake this whole release is
about. It reads the indexes that are on the tables, reads the rows, and prints
what it finds: missing or wrong indexes, rows without a `brand_id`, a nullable
`brand_id`, and every colliding value with its rows.

It never deletes anything. `--repair` rebuilds the indexes and nothing else, and
refuses while there is anything for one to reject.

### Added — the migrations are finally tested against a database with data in it

This is the actual finding. Not the missing duplicate check — the fact that no
migration path in this addon was ever run over anything but empty tables, so a
defect that only exists when rows are present had nowhere to be caught. Seven
releases went out green.

`tests/Migrations/` is a suite of its own, on a connection of its own, and it is
in both `phpunit.xml` and the new `phpunit.mysql.xml`, because the failure
behaves differently on each engine and one run cannot speak for the other.

It does not name the migration that was broken. It walks
`database/migrations/` and runs the files one at a time, seeding every LeadHub
table that exists before each one — so every migration in the addon meets rows
written by an older schema, including migrations added long after this was
written. `tests/Fixtures/released-migrations/` holds the migration sets as
published in 1.3.0, 1.4.0 and 1.10.0, and the suite installs each of them, puts
data in and upgrades forward: twenty-seven records per batch across all
seventeen tables, contacts with and without an address, events with and without
a `dedupe_key`.

The half-migrated install is not described from memory, it is produced: the
suite runs the 1.10.0 migrations exactly as published, watches them die,
confirms the dedupe index is gone, and only then applies the current ones and
requires the constraint back.

**Every check is behavioural.** "The migration ran" and "the constraint is
there" are not the same statement, and mistaking one for the other is the whole
defect. So nothing here asserts that `migrate` exited zero, or that an index of
a given name exists. It writes the row the constraint is supposed to refuse and
requires the database to refuse it — for all six identifiers, not just the one
that broke.

Demonstrated rather than asserted: with the 1.10.0 file put back in place, seven
of the fourteen cases fail. The cases that keep passing are the fresh-install
ones, which is exactly the coverage that existed before and exactly why none of
this was found.

### Changed — the index key-length probe can read the schema it is measuring

`tests/Unit/IndexKeyLengthTest.php` compiles the migrations through Laravel's
MySQL grammar in pretend mode to measure index bytes without a server. Under
`pretend()` a `select` returns nothing, so a migration that asks
`Schema::hasColumn()` or `Schema::getIndexes()` before deciding what to build was
being told the table is empty of everything — which, now that
`2026_07_24_100000` branches on exactly those answers, would have had the probe
measuring a schema no install ever holds.

It now runs two connections interleaved: the probe compiles the DDL through
MySQL's grammar, and a real SQLite database one file behind answers every
question the migrations ask about the current schema. It also tracks dropped
indexes and `modify` statements, so what it measures is the schema the last
migration leaves rather than every index that was ever created.

The NOT NULL assertion, which until now covered only `leadhub_scoring_rules`,
now covers `brand_id` on all six brand-scoped tables. It is deliberately not
extended to the second column of each pair: a contact without an email address
and an event without a `dedupe_key` are ordinary, and the unique not applying to
them is the wanted behaviour rather than a hole. What must never be nullable is
the column that scopes the rule.

### Changed — the suite can be pointed at a real MySQL server

`phpunit.mysql.xml`, ported from `goldnead/statamic-notifications` 1.0.4. Point
`DB_HOST`/`DB_PORT`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` at a throwaway
database and the identical suite runs against InnoDB. SQLite has no key-length
limit, no utf8mb4 byte arithmetic and no fixed column widths, and it reports a
different error for the same broken migration; every migration defect this
family has shipped was invisible on SQLite alone.

## [1.10.0] — 2026-07-28

Three surfaces that existed on one side only: a list of people that ignored the
brand it was rendered in, an assignment nobody was told about, and a link
between a task and a deal that could only be seen from the task.

### Assignee and owner lists are now the people of this brand

The decision behind task assignment in 1.7.0 was "assignees are the CP users of
the respective brand". It could not be built. `goldnead/statamic-brand-context`
isolates Eloquent models through a global scope, and a Statamic user is not one
of them — no `brand_id`, no membership pivot, no per-brand role — so what
shipped was every LeadHub user in every brand. The work itself was isolated and
asserted; the list of names was too wide, and it had been too wide for contact
ownership since 1.0.

brand-context 1.5.0 added the missing half: a `brand_user` table and a
`BrandMembers` facade. `Support\UserDirectory::assignable()` now asks two
questions instead of one — the LeadHub permission, then the brand membership —
and both consumers get it at once, because the task forms, the task filter, the
contact owner select and the opportunity owner select all read from that one
method. The validation side moves with it: `ResolvesCrmReferences::isAssignableUser()`
checks against the same narrowed list, so a hand-crafted request cannot park
work on somebody else's brand.

**Superusers are not exempt.** `can()` answers true for them, so the permission
half never removes a superuser — the brand half does. Holding every permission
is not the same as belonging to a brand, and a superuser who has been assigned
to one has said which one they work in.

**The transition rule is the part that matters on upgrade day.** A user with no
membership anywhere counts as a member of every brand. Every install upgrading
into this feature starts with an empty pivot table, so filtering strictly would
empty every assignee dropdown and every owner select at once — and it would read
as a permissions failure, not as a feature: the names are gone, nobody knows
why, and the fix is invisible. The rule lives inside `BrandMembers::filter()`
and is deliberately not re-implemented, pre-filtered or tightened here. Writing
the obvious strict filter instead turns five tests in
`AssigneeBrandMembershipTest` red, including the one that says an unassigned
user must still be assignable — which is the upgrade path, stated as an
assertion.

Nothing changes for a single-brand install, and nothing changes for a
multi-brand install until somebody assigns a user in **Users → Brand Members**.

### A task assignment reaches the person it was handed to

Since 1.9.0 a reassignment writes a timeline entry and fires
`LeadHubTaskAssigned`, so the history existed and an outside system could
subscribe to it. Nobody was told. The colleague holding the task found out by
opening the task list.

This release notifies them, through `goldnead/statamic-notifications` rather
than through a fourth Laravel mail notification beside the three in
`Services\LeadHubNotifier`. That class is this addon's own second invention of
the pattern, which is what justified extracting the shared one; a third would
have given the recipient two inboxes, no preferences and no digest. The
integration is optional in exactly the way the webhook-manager bridge is: when
the addon is absent, `Integrations\Notifications\NotificationsBridge::available()`
is false and every call is a no-op.

**The type is registered from the ServiceProvider, not from the controller**,
and that is not a style preference. The notifications type registry lives per
process. A type registered where the notification is produced is unknown to the
scheduled digest process, falls back to the `in_app` default there and is
silently skipped — the notification exists, is never summarised, and nothing
logs a word about it. Removing the provider registration leaves every delivery
test green and turns exactly the two registration tests red, which is what that
failure mode looks like from the outside.

**Assigning a task to yourself notifies nobody.** Contact assignment currently
does notify on self-assignment; that is the behaviour this deliberately does not
copy. Unassigning notifies nobody either. The dedupe key is scoped to the moment
rather than to the pair, so a double-submitted form is one notification and a
task that travels A → B → A reaches A twice.

The digest covered follow-ups only. `Integrations\Notifications\TaskDigestSource`
contributes open and overdue tasks under its own handle (`leadhub-tasks`; the
bundled follow-up source owns `leadhub`), so somebody carrying ten open tasks
and no follow-up no longer gets a weekly mail that says nothing about their
work. It reads through the query builder and applies the brand filter by hand,
like the bundled source, because the global scope does not apply there.

### The tasks on a deal are visible from the deal

1.9.0 let a task point at an opportunity, and only the task form could show it.
From the deal's side the link existed exclusively as the reason `destroy()`
refused — "this opportunity still has 3 tasks", naming records the screen never
showed. The opportunity edit form now carries a task panel.

It lists **every** task, completed ones included, because that is what the
deletion rule counts. A panel filtered to open work would produce the one screen
it exists to prevent: an empty list beside a refusal that names a number.

No new route and no new route parameter. The panel travels in the edit payload
and links to the task routes that already exist — the cheapest possible way not
to repeat 1.8.1, where a generic parameter name was eaten by a sibling addon's
application-wide `Route::bind()`.

### Added

- `Integrations\Notifications\NotificationsBridge`,
  `Integrations\Notifications\TaskDigestSource`,
  `Services\TaskAssignmentNotifier`, and the `crm.task_assigned` notification
  type.
- `leadhub.notifications.on_task_assignment` (default `true`).
- `tasks`, `tasksEnabled`, `canManageTasks` and `createTaskUrl` on the
  opportunity edit payload, and the panel in `Pipelines/OpportunityEdit.vue`.
- `leadhub::tasks.notifications.*` and `leadhub::pipelines.opportunity_tasks_*`
  in both locales.
- `scripts/test-notifications.sh` and a `notifications-integration` CI job,
  mirroring the webhook-manager pair. It stages the working tree rather than
  `HEAD`, so a test written five minutes ago is actually in the copy — archiving
  `HEAD` runs an empty suite and reports success.

### Changed

- `Support\UserDirectory::assignable()` filters through
  `BrandMembers::filter()`. In a single-brand install, and in a multi-brand
  install with no memberships recorded, the list is unchanged.

### Tests

- `AssigneeBrandMembershipTest` (11), `OpportunityTaskPanelTest` (6),
  `TaskAssignmentNotificationTest` (10), and
  `Integration/TaskAssignedNotificationLiveTest` (9, skipped unless the
  notifications addon is installed).
- The assignee pin in `CrmCrudBrandIsolationTest` turns into its mirror image:
  it asserted that both brands were offered the same names, and now asserts that
  a user of brand A is not offered in brand B while an unassigned user still is.
- Default suite: 427 passed, 20 skipped. Flat: 189 passed, 258 skipped, **0
  failed**.

## [1.9.0] — 2026-07-28

Three things this addon had built and could not reach, and one it had been
carrying red for months.

### The flat-file driver's failing tests were not the tests' fault

Seven to eight tests had been failing under `LEADHUB_DRIVER=flat` since well
before 1.5. Every release since proved them pre-existing, established they were
unrelated to the work at hand, and left them alone. That was the right call
each time and the wrong conclusion overall: they were reporting real defects,
and the plan that was going to make them go away — retiring the flat driver —
was revoked once it turned out adriangoldner.com keeps five live lists on it.

Five of the eight were the driver. One was the test. Two were neither, and are
the more interesting finding.

- **A UUID was being cast to an integer.** `Contact` auto-increments under the
  eloquent driver, so Eloquent adds an implicit `id => int` cast. The flat
  driver stores a UUID in `id`, and `(int) 'e3d35f29-…'` is `0`. This was not
  cosmetic: `FlatFileEventRepository` builds its log path from `$contact->id`,
  so **every contact whose UUID began with a hex letter wrote its timeline into
  `events/0.jsonl` and read back everybody else's** — roughly two contacts in
  five, silently sharing one history. The hydrator now tells each instance its
  key is a string, which removes the implicit cast.

- **An event payload could not be read back.** `Event` casts `payload` to
  `array`. A database row hands that cast a JSON string; a flat record arrives
  already decoded, so `json_decode()` was handed an array. That is a
  `TypeError`, and a 500 on any contact detail page showing a timeline entry
  with a payload. The hydrator now re-encodes values whose cast expects JSON,
  which keeps its own promise: the raw attribute map looks like a database row
  and Eloquent's cast machinery does the rest.

- **Nothing normalized an email on create.** The eloquent driver derives
  `email_normalized` in `Contact::booted()`. The flat driver wrote back
  whatever it was handed, and the CP store action hands it nothing — so a
  contact created through the Control Panel was invisible to
  `findByEmailNormalized()`, which is the lookup the entire form-submission
  dedupe path runs through. Every repeat submission from the same address
  created a second contact.

- **`findByPhoneNormalized()` queried an index bucket that was never built.**
  It had returned `null` for every contact since 1.0.

- **A follow-up could create a contact file with no contact in it.** Writing a
  follow-up for a contact with no YAML yet produced a file holding only the
  follow-up. The index skips records without a UUID, so that file was invisible
  to the index and visible to every directory scan — and the digest command
  then called `find(null)` and died on the type declaration. One malformed
  record took the whole digest down rather than being skipped.

- **`exists:leadhub_tags,id` in a driver-agnostic form request.** Two wrongs:
  a tag id is a database id under one driver and a UUID under the other, so
  `integer` is wrong half the time; and `exists` queries a table the flat
  driver never writes to. Every tag change on a flat install therefore failed
  validation — and looked exactly like a success, because a validation failure
  and a successful save both redirect back. The rule was already documented as
  a trap in `ResolvesCrmReferences` (it also bypasses the brand scope); these
  two requests had simply never been converted. Resolution now goes through the
  tag repository.

- **`CrmSyncTest` was the test's fault.** It asserted `Event::where(…)`, an
  Eloquent query, against a driver that deliberately writes timeline entries to
  files. It now asserts through the `EventRepository` contract. `SyncLog` stays
  an Eloquent query, because sync logs are database rows in both drivers.

- **The eighth test was flaky, and the flakiness was the finding.** It failed
  in the suite and passed alone. `RefreshDatabase` rolls the database back
  between tests; nothing rolled the flat-file store back, and its path is per
  *process*, not per test. So every test inherited every record written by
  every test before it — which is how a foreign contact's `events/0.jsonl` (see
  the integer cast above) reached a test that never created it. The test bed
  now empties the store and its index before each test. Both have to go: the
  staleness check compares a directory mtime against the index's `rebuilt_at`
  at one-second resolution, so deleting only the files leaves an index that
  does not know it is stale.

`tests/Feature/FlatFileDriverRegressionTest.php` pins the driver-side five. The
timeline-sharing test uses fixed UUIDs rather than generated ones, because with
random pairs it reproduced the bug about two times in five and would have
passed for the wrong reason more often than it caught anything.

### The Control Panel is now German in German

`resources/lang/de/` has been complete since 1.6.0 and none of it reached a
page heading, because the headings do not come from there. The Vue components
call `__('Tasks')`, which is Statamic's *string* translation layer and reads
`{locale}.json` from every registered package path. This addon shipped none, in
any locale, for eight releases. A German install got German navigation, a
German timeline and English headings — and not only in the CRM modules, but in
contacts and segments too.

`resources/lang/en.json` and `de.json` now ship 198 strings, harvested from all
447 `__()` call sites in `resources/js` rather than guessed, and the provider
registers the JSON path alongside the existing `leadhub::` namespace.

One property of this layer is worth stating plainly, because it is a trap:
**JSON strings from every package merge into one global dictionary**, so a key
here overrides that string across the whole Control Panel, not just inside this
addon. Translating `Save` would rename Statamic's own save button everywhere.
The addon therefore ships no key Statamic already covers, and where its own
usage disagreed with Statamic's translation the *source string* was changed
rather than the translation overridden: `Archive` (a noun in Statamic, a button
verb here) became `Archive contact`, `Schedule` became `Schedule follow-up`,
`None` became `No company`, and `Done` became `Completed`. Three keys that
existed in two spellings (`Overdue`/`overdue`, `Done`/`done`,
`Opportunities`/`opportunities`) were collapsed, and `Open` was split, because
it was serving both the adjective and the verb from one key.

One surface stayed English even after that, and only the browser found it: the
priority badge in the task list and on the contact page rendered the raw column
value — `high` in an otherwise German screen — although
`leadhub::tasks.priorities.*` had translated it since 1.6.0. The controllers now
hand down a `priority_label` next to the value; the value stays, because the
badge colour keys off it.

`TranslationParityTest` was extended to this layer the same way it guards the
PHP one — both directions, plus three rules the PHP layer does not need: every
`__()` key in the Vue sources must be covered by this addon or by Statamic, no
shipped string may be one nothing renders, and no key may shadow a Statamic
one. It also fails if a `__()` call ever passes something other than a plain
literal, since such a string could not be harvested and would be silently
unchecked.

### Reassigning a task leaves a trace

Contact assignment has written a timeline entry since 1.0. Task assignment
changed a column and nothing else, in a module whose entire purpose is that
you can answer "who gave me this, and when". It was deliberately left out of
1.7.0 because it needs a new event type, and an event type is public surface.

So it is registered as one: `Event::TYPE_TASK_ASSIGNED`,
`TimelineService::recordTaskAssigned()`, a `LeadHubTaskAssigned` event, and
`leadhub.task.assigned` in the webhook-manager trigger map. A timeline entry
alone cannot tell an outside system that work changed hands.

Two decisions worth recording. The comparison runs on assignee **ids**, not on
display labels, because two accounts can share a name. And a task with no
contact has no timeline to be written to — the event fires anyway rather than
both being dropped, which would be the version of this feature that looks built
and is not.

### A task can be attached to an opportunity

`leadhub_tasks.opportunity_id` has been a real column with a real relation and
a real delete lock built on it, and nothing in the Control Panel could set it.
The evidence is in the 1.7.0 QA run: the screenshot proving the opportunity
delete refusal had to have its blocking task created on the console, because
there was no way to make one through the interface. A refusal a user cannot
reach through the UI is one they cannot resolve through it either.

The picker is scoped to the selected contact, and refuses anything else. A flat
list of every deal in the install is not a picker, and a task attached to
another contact's deal is a data error no screen would surface again. With no
contact selected the field is disabled and says why, rather than showing an
empty dropdown — an enabled empty dropdown reads as "this contact has no
deals", which is a different claim. A closed deal stays visible while a task
still hangs on it, or saving the edit form would silently detach it.

The option feed takes the contact as a **query** parameter rather than a route
parameter. `{contact}` and `{opportunity}` are exactly the kind of generic name
a sibling addon may already have claimed with `Route::bind()`, and that binding
is application-wide — which is what 1.8.1 fixed for `{rule}`. A query string
cannot be captured that way.

### Added

- `Event::TYPE_TASK_ASSIGNED`, `Events\LeadHubTaskAssigned`,
  `TimelineService::recordTaskAssigned()`, and the `leadhub.task.assigned`
  webhook-manager trigger.
- An opportunity picker on the task create and edit forms, a
  `GET /tasks/opportunity-options` feed, `opportunity_id` on
  `StoreTaskRequest`/`UpdateTaskRequest` validated through the model, and
  `Support\OpportunityPicker`.
- `resources/lang/en.json` and `resources/lang/de.json` (198 strings), and
  `addJsonPath()` in the provider.
- `resources/js/support/OpportunityPicker.vue`.

### Fixed

- Flat-file driver: UUID keys cast to integers, colliding event logs, JSON
  payloads that could not be read back, unnormalized email and phone on create,
  an unbuilt `by_phone_normalized` index bucket, and follow-ups creating
  identity-less contact files.
- `SendFollowupDigestCommand` skips a follow-up with no contact instead of
  raising a `TypeError`.
- `exists:leadhub_tags,id` removed from `StoreContactRequest` and
  `UpdateContactRequest`.

### Tests

- `FlatFileDriverRegressionTest`, `TaskAssignmentHistoryTest`,
  `TaskOpportunityLinkTest`, and seven new cases in `TranslationParityTest`.
- The test bed empties the flat-file store between tests.
- Default suite: 400 passed, 11 skipped. Flat: 189 passed, 222 skipped, **0
  failed** — for the first time in this addon's history.

## [1.8.1] — 2026-07-29

**Fixes a defect in 1.8.0 that only appears alongside another addon.** The
scoring rule write routes were `/scoring/{rule}`, and
`goldnead/statamic-webhook-manager` registers `Route::bind('rule', …)` in its
provider for its own Rule model. A route-model binding is application-wide, not
per package: it applies to every route with that parameter name, in every addon.
So on any install with both addons, editing or deleting a scoring rule was
resolved against the webhook manager's rule repository, which had never heard of
that id, and returned 404. The button did nothing and said nothing.

The parameter is now `{scoringRule}`. Nothing else changed, and no URL a user
would have bookmarked is affected — these are a PATCH and a DELETE.

Two things about how this was missed are worth writing down, because both were
structural rather than careless:

- **The addon's own suite could not have caught it.** `SubstituteBindings` is
  part of Statamic's real CP middleware group, but the test bed mounted the CP
  routes without it, so a `Route::bind()` registered by anything had no effect
  at all in tests. The middleware is now part of the test route group, which is
  what makes the new regression test in `ScoringRuleCrudTest` fail on the old
  parameter name and pass on the new one. Nothing in this addon uses implicit
  model binding, so adding it changes no other behaviour.
- **Route parameter names are a shared namespace.** `{rule}`, `{template}`,
  `{webhook}`, `{endpoint}` are all generic enough that a sibling addon may
  already own them. Prefer a name nobody else would pick.

Found by driving the real Control Panel of a Hub that has both addons
installed — not by a test, and not by reading the code.

## [1.8.0] — 2026-07-29

The engagement score has worked since 1.2 and appeared nowhere. It moved on
every scored activity, a QA run watched a contact go from 0 to 10, and the only
place the number occurred in the entire Control Panel was as a selectable field
in the segment rule builder — one could filter on something that could not be
seen. This release puts it on screen, gives the point table a Control Panel, and
gives the score a past.

The expensive half of that is the point table. It could have stayed in
`config/leadhub.php` with a read-only screen, and that would have been the right
call for a single-tenant install. It is the wrong call here, because the table
in the config file is one table for every brand: a Hub running three brands
could not decide that a purchase is worth more to one of them than to another
without deciding it for all three. That, and not the editing, is why the rules
moved into the database.

### Added

- **The engagement score on screen.** On the contact detail page in the details
  panel, and as a column in the contact list — sortable and filterable by a
  range, both server-side, because "who are my hottest leads" must not stop at
  the twenty-five rows of the current page. The filter accepts a floor of `0`
  as a filter rather than as an empty value: `0` is the score every contact
  starts at and is exactly what somebody filters on to find the leads nothing
  has happened to yet. Every one of these is gated on `features.scoring`; an
  install that never enabled it does not grow a column of zeros.

- **A rule screen under LeadHub → Scoring**, with create, edit, activate,
  deactivate and delete. Rules live in the new `leadhub_scoring_rules` table and
  are brand-scoped like everything else in this addon, which is the whole point:
  the same activity can be worth 50 points in one brand and 3 in another.

  The table models what the config block could already express, and one thing
  more:

  - `event_type` + `points`, one row per activity type;
  - `event_type = '*'` is the catch-all — the old `scoring.default`, expressed
    as a row so a brand can set its own baseline instead of inheriting a single
    global value;
  - `enabled`, the addition. A Control Panel needs a way to park a rule without
    losing it. A disabled rule behaves exactly as an absent one and falls
    through to the catch-all — "off" has to mean "as if it were never written",
    because awarding zero would be a silent third behaviour.

  Two schema decisions worth knowing. `brand_id` is NOT NULL, unlike every other
  LeadHub table where it was retrofitted onto existing rows: the unique index is
  `(brand_id, event_type)`, and a unique index does not constrain NULLs, so a
  nullable brand column would have enforced nothing at all for exactly the rows
  without a tenant. And `event_type` is `varchar(100)`, not the default 255 —
  400 bytes in that index instead of 1020. Which brings us to:

- **`tests/Unit/IndexKeyLengthTest.php`**, ported from `statamic-notifications`
  v1.0.4, where an oversized unique took a release down on production while the
  SQLite suite stayed green throughout. It compiles this addon's own migrations
  through Laravel's MySQL grammar in pretend mode — no server, no connection —
  and measures every index MySQL would receive. LeadHub needed it the moment it
  gained a unique over a varchar. It found three pre-existing indexes over half
  the key limit (`leadhub_events` and `leadhub_opportunities` on
  `(source_type, source_id)`, `leadhub_tasks` on
  `(assignee_id, status, due_at)`). None is broken — all three are legal at
  ~2040 bytes of 3072 — so they are pinned at their measured width rather than
  exempted: widening one now fails the test. Narrowing them means altering
  columns on live tables, which is its own release. Recorded in GAPS.md.

- **`php artisan leadhub:scoring:import`** — copies the config point table into
  the rules table, per brand, with `--dry-run`, `--force` and `--brand=`.
  Idempotent: a second run changes nothing, and a rule whose points differ from
  the config file is left alone, because a rule that differs is a rule somebody
  edited and an import must never be a scheduled way to silently revert the
  Control Panel.

- **A `score_changed` timeline entry** on every real score change, with the old
  and new value, the delta and the activity that caused it. `LeadHubContactScoreChanged`
  has fired since 1.2 and nothing listened for the purpose of recording it, so a
  contact's score had a value and no past. The summary is composed at write time
  and stored, like every other entry type since 1.6.0 — a timeline rendered from
  live rules would rewrite its own history whenever a rule was edited.
  Aggregation was the alternative and was rejected: a summarized history cannot
  answer "what exactly awarded these 3 points", which is the only question
  anybody opens a score history for. `leadhub.scoring.timeline` turns the
  entries off without losing the event.

- **`leadhub.score.changed` as a webhook-manager trigger.** A new event type is
  a public surface; without the registration it is a line in a timeline nobody
  polls. Registering it exposed a real defect in `LeadhubTrigger::build()`: it
  assumed every LeadHub event extends `LeadHubEvent`, and `LeadHubContactScoreChanged`
  deliberately does not — it carries a score-specific payload instead of the
  generic actor/metadata shape. The builder would have treated the event itself
  as the contact and produced a webhook with no source reference and a body of
  nothing. It now resolves the contact either way and carries the event's own
  payload as the metadata.

- **A `manage leadhub scoring` permission.** Separate from the contact
  permissions, and separate from the other CRM ones, because the point table
  decides segment membership for every contact at once — a different blast
  radius from editing one record.

### Changed

- **`config/leadhub.php` → `scoring` is now a fallback, not the live table.**
  This is the part of the release that could have done real damage, so it is
  worth stating plainly: **an upgrade to 1.8.0 changes no score.** With no rules
  in the table — which is every install the moment it updates — `ScoringService`
  reads the config file exactly as before. The table only takes over once a
  brand has at least one rule. An empty table meaning "everything scores the
  default" would have silently rescored every install that upgraded, and
  because scores steer segments and segments steer who receives mail, that would
  have been the worst outcome available to this feature. The rule screen says
  so on its face when a brand has no rules yet, instead of leaving an empty list
  to read as "nothing is scored here".

### Deletion

Scoring rules delete outright, and that is the house rule (L1) applied rather
than suspended. The rule refuses a delete while something still hangs on the
record; nothing hangs on a scoring rule. No table carries a rule id, timeline
entries store numbers and a composed sentence rather than a reference, and a
contact's `engagement_score` is a running total, not a sum recomputed from
rules. A block here would be a lock on a door with no room behind it. What
deleting does change is the future — the type falls to the catch-all, and
deleting the last rule of a brand hands scoring back to the config file — so
the confirmation dialog says that instead of the controller refusing.

### Tests

`ScoringRuleCrudTest`, `ContactScoreVisibilityTest`, `ScoreTimelineEntryTest`,
`ScoringRuleImportCommandTest`, `ScoringRuleBrandIsolationTest` and
`IndexKeyLengthTest` — all against the real routes, none against the models.
Every test that creates a rule through the HTTP route also asks the scoring
engine what it would now award: a test that only checked the row exists would
pass for a screen wired to nothing.

The cross-brand tests are worth calling out because they cover two different
failures. One is visibility — brand A's rule must not appear in brand B's list
or be reachable by id — and that one is loud. The other is arithmetic: a rule
must not *compute* in the wrong tenant. That failure produces no screen, no
error and no log entry; a contact simply receives the wrong number of points,
which moves segment membership, which decides who gets mail. It is asserted
directly (`awards each brand its own points for the same activity`,
`does not let a brand A catch-all rule set brand B's baseline`) rather than
inferred from the list being empty.

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
