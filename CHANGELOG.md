# Changelog

All notable changes to Invitation Studio are recorded here. The project has not been formally
released yet; the current work is collected under **Unreleased**.

## Unreleased

### Added — Pledge import/export UI, capability-aware UI & data-integrity QA

- **Pledge import/export operator UI** (`frontend/src/pages/PledgesImportPage.tsx`, route
  `/events/:id/pledges/import`): template download → upload (type/size validation) → column mapping from the
  backend's detected columns/suggested mapping → **non-mutating preview** (validation errors, matches,
  duplicates, conflicts, ambiguous identities) → explicit create/update/skip decisions for ambiguous rows →
  explicit **Confirm** (guarded against double-submit) → results summary from the backend's real counts →
  row-level error report (CSV always; XLSX only when PHP `zip` is present, else a clear 503 message) → event
  XLSX export. Preview never mutates; confirmation is explicit; ambiguous rows are never guessed.
- **Capability-aware UI**: Pledges/Check-in links and actions are hidden/disabled from the event's actual
  `capabilities`; direct URLs to a disabled feature render a friendly disabled state rather than a raw 403;
  `check_in` and `contributions` are handled independently; an event capability editor was added to the event
  form (taxonomy-driven, non-wedding-only). Frontend visibility is not authorization — the backend still
  enforces 403.
- **Data-integrity QA (no behaviour change; regression tests only)**: pledge summary semantics pinned
  (cancelled pledges counted but excluded from money totals; per-currency separation; celebration roll-up
  aggregated once), import/error-report format behaviour pinned (CSV default; XLSX requires `zip`, else
  graceful 503), private import-source access + retention recommendation documented (no cleanup added, no
  files deleted), reminder eligibility rules pinned (acceptance ≠ delivery), and a secret-exposure audit with
  regression tests (credential secrets hidden; sandbox capture redacted). New tests: `PledgeSummaryTest`,
  `ImportFormatTest`, `ReminderEligibilityTest`, `SecretExposureTest`, `PledgeImportSourceTest`.

### Known limitations

- `by_method.amount_minor` in the pledge summary is currency-agnostic (the per-currency money rows are
  correct); the UI shows per-method entry counts only. Product decision to make it currency-aware.
- `EntryCredentialNotification` carries the decrypted code in the queued payload (in-memory only; redacted
  from delivery metadata). Secret-at-rest is hashed/encrypted; the queue payload is an architectural
  follow-up.
- `invite_token` is intentionally returned to the authenticated owner and included in the guest export
  (pre-existing, unchanged, documented risk).
- Browser verification and live-provider integration were not performed; migrations remain pending in the
  target environment.

### Added — Pledges operator UI & server-side capability enforcement

- **Pledges operator UI (Workstream A).** New `/events/:id/pledges` list (search + status/method filter +
  pagination) and `/events/:id/pledges/:pledgeId` detail: manual create/edit, pledge snapshot identity,
  pledged/reported-fulfilled/outstanding per currency (never combined), derived status badges
  (`open`/`partially_fulfilled`/`fulfilled`/`over_fulfilled`/`cancelled`), append-only fulfillment history
  with record + reversal (confirmation, no history edits, no client-side clamping), cancel with
  confirmation, event summary, celebration roll-up (`reports/celebrations/{celebration}/pledges`) and event
  XLSX export. Copy states amounts are *reported* and that Invitation Studio does not collect/verify money;
  pledge status never gates admission. New `frontend/src/lib/money.ts` and reusable
  `PledgeSummary`/`PledgeBadges`/`EntityPicker`/`PledgeFormModal` components.
- **Server-side capability enforcement (Workstream C).** `check_in` and `contributions` are now
  **implementable but default-off** (`implemented => true`, `default => false`); an event opts in via
  `settings.capabilities` (validation already keys off `isImplemented()`). A new fail-closed route middleware
  `App\Http\Middleware\EnsureEventCapability` denies the corresponding endpoints with **HTTP 403** when the
  capability is not enabled — pledge/import routes require `contributions`; check-in/attendance and operator
  credential routes require `check_in`. Denial is enforced server-side; frontend hiding is not authorization.
  `EventCapabilities::resolve()` was corrected so an event-type `default_capabilities` opt-in is honored
  (still no shipped type opts in). Account/Celebration pledge reports remain ownership-scoped aggregates (not
  per-event gated, since they span events with mixed capabilities). Key events/capabilities remain separate
  concepts. New `tests/Feature/Api/EventCapabilityEnforcementTest.php`; capability unit/feature tests updated.

### Remaining (not implemented)

- Capability **UI gating** (the pledged/check-in pages do not yet hide themselves when the capability is
  disabled — the backend returns 403, and the pages surface an error). No browser verification performed.
- Pledge **import wizard UI** (Workstream B) is not built; the import/export backend exists.
- No live provider integration; migrations remain pending in the target environment.

### Security

- **Invitation-token rate limiting corrected.** The public `invite-token` limiter was keyed only on the
  requested token, so an attacker gained a fresh bucket for every guessed token. It now enforces two
  independent limits: a per-client-IP cap (default 30/min) and a per-token cap (default 5/min)
  (`invitations.rate_limits.invite_token_ip_per_minute` / `invite_token_per_minute`).
- **Operator verification endpoint rate-limited.** `GET /api/v1/events/{event}/guests/{guest}/verification`
  now sits behind `throttle:admin-read` (default 120/min per user/IP) instead of being unthrottled.

### Fixed

- **Unavailable transports are never reported as success.** SMS/WhatsApp `not_configured` outcomes are now
  recorded as a sanitized `failed` delivery (`meta.provider.reason = not_configured`) instead of silently
  leaving the row `queued`. `SendInvitationJob`/`SendReminderJob` set `guests.sent_at`/`reminded_at` only
  when at least one channel actually reached `sent`/`delivered`, so a missing/unconfigured transport no
  longer produces a misleading "invited"/"reminded" marker while nothing was delivered.

### Tests

- Added `tests/Feature/InviteSecurityTest.php` (limiter keying, verification throttling) and
  `tests/Feature/DeliveryNotConfiguredTest.php` (unconfigured transport failure recording and markers).

### Added — Entry credentials & event-day check-in

- **Separate entry credential (not the RSVP token).** `guests.invite_token` remains the RSVP link bearer
  only. A new additive `invitation_credentials` table holds one *current* credential per guest per event,
  enforced by a unique nullable `active_key`. The secret is stored **hashed** (`code_hash`, deterministic
  HMAC-SHA256 keyed on `APP_KEY`, for lookup) and **encrypted at rest** (`code_encrypted`) for deliberate,
  access-controlled reveal/re-send. No plaintext column; the secret is hidden from serialization.
- **Lifecycle.** `App\Services\CredentialIssuer` issues/activates on RSVP **accept**, revokes on
  **decline**, and supports operator **reissue** (revokes the current row and mints a replacement linked via
  `replaces_id`). Issuance is idempotent and safe under concurrency (`unique(active_key)`, `unique(code_hash)`).
  Codes use a Crockford-style alphabet (no 0/O/1/I/L/U), 8 chars (~40 bits).
- **Validity independent of the RSVP link.** `App\Support\CredentialAccess` computes an event-day window
  (default from `event_start − 6h` to `event_start + 12h`, configurable via
  `invitations.credential_valid_before_hours` / `credential_valid_after_hours`), with explicit states
  (`valid`, `not_active`, `revoked`, `expired`, `not_yet`, `unavailable`). The RSVP link still expires at
  event start; the credential does not.
- **Operator check-in.** `Admin\CheckInController`: `lookup` (read-only; **never** records attendance),
  `store` (explicit check-in; transaction + `lockForUpdate` + unique index prevent duplicates), `index`,
  and `reverse` (audited, preserves the original row and allows a corrected check-in). Attendance is a new
  additive `attendances` table with `checked_in_at`, `checked_in_by` (operator attribution), `method`,
  `party_size`, `status` and reversal fields. Operator credential control: `Admin\CredentialController`
  (`show`/`issue`/`reissue`/`revoke`/`reveal`). Policies: `InvitationCredentialPolicy`, `AttendancePolicy`.
- **Endpoints:** `GET/POST /api/v1/events/{event}/guests/{guest}/credential[/reissue|/revoke|/reveal]`,
  `GET /api/v1/events/{event}/check-in/lookup`, `POST /api/v1/events/{event}/guests/{guest}/check-in`,
  `GET /api/v1/events/{event}/attendances`, `POST /api/v1/events/{event}/attendances/{attendance}/reverse`.
- **Tests:** `tests/Feature/Api/CredentialCheckInTest.php` (lifecycle, authorization, validity window,
  lookup-does-not-write, duplicate rejection, mismatched code, reversal history, audit attribution).

### Added — Secure entry-credential delivery

- **Credential-aware messages.** `App\Notifications\EntryCredentialNotification` composes email, SMS and
  WhatsApp entry-code messages (event name/date/venue + the code + a "keep it private" instruction); it
  respects `invitations.channels`, the Delivery Lab allowlist and the existing per-channel delivery rows
  (new `credential` kind — additive `invitation_deliveries.kind` migration).
- **Sending is reuse-only.** `App\Jobs\SendEntryCredentialJob` sends the guest's **current active**
  credential and never mints a new one (no competing active credentials). `POST
  /api/v1/events/{event}/guests/{guest}/credential/resend` (operator, `throttle:admin-send`) queues it and
  returns `422` when there is no active credential. Email sends inline via `MailDelivery`; SMS/WhatsApp use
  their channels, so provider acceptance stays distinct from confirmed delivery and unconfigured channels
  record a sanitized `not_configured` failure (never success).
- **Secret redaction.** SMS/WhatsApp payloads carry `redact_secret`; `SandboxMessageTransport` replaces the
  code with `[redacted]` before storing `meta.captured.message`, so entry codes never persist in Delivery
  Lab capture, delivery metadata or logs. `$hidden` is not relied on alone. The `credential` kind appears in
  delivery history/Delivery Lab like other kinds.
- **Tests:** `tests/Feature/Api/EntryCredentialDeliveryTest.php` (email+kind, secret redaction in sandbox
  capture, unconfigured-channel failure, reuse-only/no-active-credential, resend authorization, revoked
  resend rejection).

### Added — Pledges (information management), Excel import/export and operator UI

- **Pledges are recorded information, not collected money.** No payment gateway/checkout/banking/mobile-money
  integration exists, and reported fulfillment is never labelled verified by Invitation Studio. An
  outstanding pledge never blocks invitations, RSVP, credential issuance or check-in.
- **Data model (additive):** `pledges` (event/account-owned; optional `guest_id`/`contact_id` `nullOnDelete`
  with snapshotted `pledger_name`/email/phone; `currency` + `pledged_amount_minor` integer minor units;
  `source`; `status`; `created_by`; `import_batch_id`), `pledge_fulfillments` (append-only; partial/multiple;
  `recorded`/`reversed` with `reverses_fulfillment_id`), `pledge_import_batches`.
- **Domain:** `App\Services\PledgeLedger` (create/edit/cancel, record/reverse fulfillment, derived
  `fulfilled`/`outstanding`/status `open|partially_fulfilled|fulfilled|over_fulfilled`), `PledgePolicy`
  (owner + staff-of-owner, account-scoped), `Admin\PledgeController` (list/search/filter, create, edit,
  cancel, fulfillments, reverse, event summary, account report, celebration roll-up by aggregating member
  events). Money is grouped by currency; over-fulfillment is allowed and surfaced (never clamped); currency
  is immutable once fulfillment exists. Every mutation is audited via `AuditLogger`.
- **Excel import/export:** `Admin\PledgeImportController` + `App\Services\PledgeImporter` — upload → detected
  columns + suggested mapping → **non-mutating preview** (row-level validation, proposed create/update/skip,
  guest/contact matching, ambiguity flags) → explicit **confirm** → batch summary (created/updated/skipped/
  failed) with `import_batch_id` lineage. Safe matching: explicit guest/contact identity first, then
  normalized email/phone, then (only as a fallback) same email/phone **+ currency + exact amount**; name+amount
  is never used; ambiguous rows require an explicit decision and are never silently merged. Re-import is
  idempotent (no duplicate pledges; fulfillments deduped by pledge+amount+date+reference). Downloadable
  error report and XLSX template; event pledge XLSX export.
- **Operator UI:** new `CheckInPage` (`/events/:id/check-in`) — manual short-code entry, read-only lookup,
  explicit confirm (and an audited override path with required reason), the seven lookup states, arrival
  totals, recent arrivals, and reversal with a required reason; credential controls in `GuestsPanel`
  (status + issue/reveal/resend/reissue/revoke behind confirmation). Status is backend-authoritative.
- **Tests:** `tests/Feature/Api/PledgeTest.php` (26) and `tests/Feature/Api/PledgeImportTest.php` (16).

### Remaining (not implemented)

- Capability flags `check_in` and `contributions` remain **force-off** (backend enforcement + tests to
  enable them are outstanding). No browser verification was performed. No live provider integration.
- Pledge export/import UI screens are not built (backend endpoints + check-in/credential UI exist);
  the pledges frontend screens (list/detail/manual entry/import wizard/reports) are pending.

### Remaining (not implemented in this increment)

- **Entry-credential message delivery** — credential-aware SMS/WhatsApp/email composition, re-send, and
  redaction of entry secrets from Delivery Lab sandbox capture, exports and logs.
- **Operator UI** — check-in screen, credential status/reissue controls, pledge screens. No frontend
  changes were made in this increment; the UI was not browser-verified.
- **Pledges** — next separate workstream: event-level pledge/fulfillment data management with first-class
  Excel import (mapping/preview/validation/dedupe/summary/error report/template), manual entry, safe
  re-import, audit history and reports. **Information management only — no payment collection/processing.**
- Reminder-eligibility unification; enabling the `check_in` / `contributions` capability flags; live
  provider integrations; scheduler wiring.

## 2026-10-15

This checkpoint consolidates the current work-in-progress implementation: the React SPA migration,
event types/capabilities, celebrations, reusable contacts, invitation access/QR hardening, and the
Delivery Lab. No formal version number has been assigned.

### Added

- **Event types and capabilities** — configuration-driven taxonomy (`config/event_types.php`) with
  presentation metadata and defaults, plus an explicit, validated capability map
  (`settings.capabilities`) resolved through `App\Support\EventCapabilities`. Only shipped workflows are
  togglable; recognised-but-unimplemented capabilities cannot be enabled. Additive `GET /api/v1/event-types`.
- **Celebrations** — account-scoped grouping of related events (`celebrations` table, nullable
  `events.celebration_id` with `nullOnDelete`) with list/create/view/update/delete and attach/detach
  endpoints, `CelebrationPolicy`, and React pages. Deleting a celebration detaches its events.
- **Reusable contacts** — account-scoped `contacts` table with normalized, account-unique email/phone,
  nullable `guests.contact_id` (`nullOnDelete`), `App\Services\ContactResolver` (+ `ContactResolution`),
  and a shared `App\Support\ContactNormalizer`. Resolution is integrated into guest creation, updates and
  imports. Contacts API (`/api/v1/contacts`) with policy, search/pagination, detail with paginated linked
  guests, safe editing and deletion; audit logging on update/delete. React Contacts pages.
- **Contact backfill tooling** — `contacts:backfill` (dry-run by default, `--apply`), `contacts:backfill-rollback`
  and `contacts:verify`, with a JSON run journal and parity/integrity verification.
- **Invitation access/QR** — absolute invitation URL accessor, SVG QR endpoints
  (`/invite/{token}/qr`, `/api/v1/public/invites/{token}/qr`), and `App\Support\InvitationAccess`
  centralising availability/expiry.
- **Operator verification endpoint** — `GET /api/v1/events/{event}/guests/{guest}/verification` returning
  validity + minimal guest/event details (no token or contact data).
- **Delivery Lab** — `config/delivery_lab.php` and `App\Support\DeliveryLab` (disabled-by-default
  allowlist guard); a transport abstraction (`MessageTransport`, `HttpMessageTransport`,
  `SandboxMessageTransport`, `TransportResolver`); lab endpoints (`GET /api/v1/delivery-lab`,
  preview and test-send per event/guest) and a React Delivery Lab page with channel readiness, counts,
  message previews, sandbox test sends and a delivery detail view.
- **Import conflict reporting** — rows whose email/phone resolve to different contacts are counted in
  `failed_count` (distinct from routine skips) and surfaced in the notification and import UI.

### Changed

- **Frontend migrated to a standalone React 18 + TypeScript + Vite SPA** (`frontend/`), served
  same-origin by Laravel via `SpaController` and a guarded route fallback; the build outputs to
  `public/app`. Legacy routes redirect, and Blade remains for auth/profile/audit/staff and the public
  `/invite/{token}` flow.
- Invitation and reminder content now uses the **absolute** invitation URL (from `APP_URL`) in email,
  SMS, WhatsApp and QR payloads, while the relative form remains for same-origin links.
- Delivery jobs are idempotent against duplicate/retried jobs (guest `sent_at`/`reminded_at` guards and
  per-attempt delivery-row reuse); provider/channel results are captured consistently.
- Guest delivery rows exclude Delivery Lab test rows from retry reuse; job failure messages are sanitized.

### Fixed

- Public invitation pages no longer error when an event is missing/soft-deleted — they return a clean
  unavailable/expired response (410) or 404 for QR.
- QR generation no longer depends on the Imagick extension (now SVG).
- Duplicate normalized contact identifiers are rejected with clear 422 responses, including a
  database-uniqueness race path; the same applies to conflicting guest identifier updates.
- Partial imports no longer leave orphan contacts; a failed row rolls back atomically.

### Security

- Delivery webhooks fail closed without a configured secret, verify it in constant time, are idempotent,
  and refuse to regress terminal delivery states.
- Provider errors are sanitized; credentials, authorization headers and message contents are never
  stored, logged or returned. Delivery Lab responses and the UI never expose secrets.
- The Delivery Lab guard prevents non-allow-listed or unauthorized test sends from reaching providers and
  never silently falls back to live delivery; sandbox capture performs no network I/O and never fabricates
  a provider reference.
- Invitation tokens are not exposed in public responses; `guests.contact_id` is hidden from guest
  serialization.

### Tests

- Added suites for event types/capabilities, celebrations (including event workflow), contacts
  (foundation, resolver, authorization, CRUD), contact backfill parity, invitation URLs/verification,
  provider results, webhooks, retry safety, the Delivery Lab guard, and the Delivery Lab (dashboard,
  preview, sandbox/test-send). The PHPUnit database guard restricts runs to `invitation_app_testing`.

### Documentation

- Added this changelog. Rewrote `README.md` to document the actual application, architecture, setup,
  configuration, security notes and roadmap.

### Compatibility and migration notes

- Purely additive migrations: `create_celebrations_table`, `add_celebration_id_to_events_table`,
  `create_contacts_table`, `add_contact_id_to_guests_table`, `create_guest_imports_table`. No destructive
  changes and no data backfills are required to run the app; the contact backfill is an explicit, opt-in
  command.
- Existing invitation tokens and already-sent invitation links are preserved; the URL format is unchanged
  (only made absolute for external channels).
- Guest API response shapes are unchanged (new fields like `celebration_id`/`contact_id` are additive or
  hidden). Legacy Blade pages for `/admin/*` remain available.
