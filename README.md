# Invitation Studio

Invitation Studio is a managed event-invitation platform. An operator (the service) configures
events, manages guest lists, designs invitations, delivers them by email/SMS/WhatsApp, and collects
RSVPs. Guests open a personal invitation link — they never create an account or touch the management
system.

The application is an **operator-managed service**, not a self-service SaaS: the operator handles
client requests, set-up, and delivery. That framing drives the current feature set and the delivery
tooling.

## Project overview

- **Event** — a single occasion with its own date, venue, timezone, capacity, settings, invitation
  design, guest list, RSVPs and delivery history.
- **Celebration** — an optional account-scoped *grouping* of related occasions (for example a kitchen
  party, a send-off and a ceremony). A celebration groups events; it never owns or merges their data.
- **Event type** — a configuration-driven classification (wedding, send-off, kitchen party, birthday,
  graduation, corporate, custom) that supplies presentation metadata and defaults. It does **not**
  restrict which capabilities an event may use.
- **Contact** — a reusable, account-scoped identity shared across an account's events. Guest records
  remain event-specific; a contact is linked from many guests.

### Implemented vs planned

Implemented and covered by automated tests: event/guest management, event types and capabilities,
celebrations, contacts and contact resolution, guest import/export, invitation links and SVG QR codes,
public invitation access with RSVP, reminders, multi-channel delivery tracking, webhook status
callbacks, retry safety, and the Delivery Lab (readiness, previews, safe test sends, sandbox capture).

Requires manual/browser verification: Delivery Lab UI end-to-end, live email/SMS/WhatsApp provider
delivery, and phone-based QR scanning through a tunnel.

Not implemented (future work): live provider integrations, event-day attendance/check-in, contributions
and pledges, contact merge/unmerge, and browser-level Delivery Lab verification.

## Core workflows and features

### Events, guests and settings
- Operators create/edit/duplicate/delete events (soft delete) with title, description, start, timezone,
  venue and capacity. Past events cannot be edited; events with pending invitations cannot be deleted.
- Guests are event-specific records (`guests`): name, email, phone, status (`pending`/`accepted`/
  `declined`), an invitation token, and `sent_at`/`reminded_at` timestamps. Guest CRUD is paginated with
  status/search filters and event-wide counts.
- **Event types & capabilities**: types come from `config/event_types.php`; explicit, validated
  capabilities (`custom_questions`, `plus_ones`, plus placeholders that are recognised but cannot be
  enabled yet: `waitlist`, `check_in`, `households`, `contributions`) live in `settings.capabilities`.
  Legacy settings (`allow_plus_ones`, `custom_questions`, `enable_waitlist`) remain honoured.

### Celebrations
- `celebrations` is account-scoped; `events.celebration_id` is nullable with `nullOnDelete`, so deleting
  a celebration **detaches** its events (they become standalone) and never deletes them or their guests.
- An event may belong to zero or one celebration; attachments are validated against the same account.

### Contacts (reusable identities)
- `contacts` is account-scoped (`user_id`) with `name`, nullable `email`/`phone`, `normalized_email`/
  `normalized_phone` (account-scoped unique), and `notes`. `guests.contact_id` is nullable with
  `nullOnDelete`.
- `App\Services\ContactResolver` links a guest to a contact using approved rules: normalized email first
  (trim + lowercase), then normalized phone (digits only, ≥7 digits, leading zeros preserved, national
  and international formats deliberately **not** equated). Name is never used for matching. If email and
  phone match *different* contacts the resolver reports a conflict and links nothing. A guest with no
  usable identifier gets its own contact. Matching is always scoped to the owning account.
- Resolution is integrated into guest creation, guest updates (existing associations are preserved;
  conflicting identifier changes are rejected with 422) and guest import.
- **Guest details remain authoritative** for each event: editing a contact never rewrites guest name,
  email or phone, and never reassigns guests.
- The account-scoped Contacts API and React pages provide list/search/pagination, contact detail with
  paginated linked guests, safe editing (duplicate identifiers → 422; unique-race → 422), and deletion
  that detaches guests without deleting them. Contact `update`/`delete` are audit-logged.

### Guest import & export
- Imports accept `.csv`, `.xlsx`, `.ods` (≤5 MB), require a `name` column, and run as a queued job
  (`GuestImport`) with per-import status and created/skipped/failed counts.
- Resolution reuses account contacts per row. Blank-name and duplicate rows are counted as **skipped**;
  rows whose email and phone resolve to different contacts are counted separately as **failed** so the
  operator can act on them. Failed imports leave no orphan contacts.
- Export produces XLSX including the guest invitation token (authorized admins only).

### Invitation links, QR and public access
- Each guest has a unique 32-character invitation token (`Str::random(32)`), guarded from mass assignment
  and kept stable so existing links never break.
- The **delivered** invitation link is absolute (`APP_URL` + `/invite/{token}`), used in email, SMS and
  WhatsApp bodies and encoded into the QR code. The relative form is retained for same-origin browser
  links.
- QR endpoints return **SVG** (`image/svg+xml`) so they do not depend on the Imagick PHP extension:
  `GET /invite/{token}/qr` and `GET /api/v1/public/invites/{token}/qr`.
- Public access is token-scoped and rate-limited by **two independent limits** — a per-client-IP cap
  (default 30/min) and a per-token cap (default 5/min), configured under
  `invitations.rate_limits.invite_token_ip_per_minute` / `invite_token_per_minute`. Limiting on the token
  alone was insufficient because an attacker could obtain a fresh bucket for every guessed token. Validity
  is centralised in `App\Support\InvitationAccess`:
  **unavailable** (event missing / soft-deleted) and **expired** (event already started, or older than
  the optional token TTL) both return a clean `410` (browser page / JSON) or `404` for QR — never a 500.

### Entry credentials & event-day check-in

- **Entry credentials are separate from the RSVP link token.** `guests.invite_token` remains the RSVP link
  bearer only. `invitation_credentials` holds at most one *current* credential per guest per event (unique
  nullable `active_key`). The secret is stored **hashed** (`code_hash`, HMAC-SHA256 with `APP_KEY`, used for
  lookup) and **encrypted at rest** (`code_encrypted`) so an authorized operator can deliberately reveal or
  resend it; there is no plaintext column and the secret is hidden from JSON serialization.
- **Lifecycle** (`App\Services\CredentialIssuer`): issue/activate on RSVP **accept**, revoke on **decline**,
  and operator **reissue** that revokes the current credential and links the replacement (`replaces_id`).
  Codes are 8-char Crockford-style (~40 bits). Issuance is idempotent and concurrency-safe.
- **Validity is independent of the RSVP link.** `App\Support\CredentialAccess` gives an event-day window
  (`event_start − 6h` … `+ 12h` by default; `invitations.credential_valid_before_hours` /
  `credential_valid_after_hours`) with explicit states (`valid`, `not_active`, `revoked`, `expired`,
  `not_yet`, `unavailable`). The RSVP link still expires at event start; the entry credential does not.
- **Check-in** (`Admin\CheckInController`) is an explicit operator action. `lookup`/scan is read-only and
  never records attendance; `store` records an `attendances` row (timestamp, operator, method, party size)
  inside a transaction with a unique index preventing duplicates; `reverse` is audited and keeps the
  original row so a corrected check-in can follow. QR possession or invitation verification is **not**
  attendance. Outstanding pledges (future workstream) will not affect admission.
- **Entry-code delivery** reuses the existing delivery architecture via
  `App\Notifications\EntryCredentialNotification` + `App\Jobs\SendEntryCredentialJob` (delivery `kind =
  credential`; resend endpoint `POST .../guests/{guest}/credential/resend`). It only ever sends the
  **current active** credential. Entry codes are stored hashed + encrypted, and are **redacted from
  sandbox/captured delivery content** (`redact_secret` → `[redacted]`) so they never persist in Delivery
  Lab capture, metadata or logs. Unconfigured channels record a sanitized failure, not success.

### Pledges (information management) & Excel import

- Pledges record information clients provide (often Excel) or that operators enter. **Invitation Studio does
  not collect, hold, transfer or verify money.** Outstanding pledges never block invitations, RSVP,
  credentials or check-in. Pledges are event-owned; Celebration totals aggregate member events.
- Amounts are integer **minor units** with an explicit currency; fulfillment is **append-only** (partial,
  multiple, reversible with history). APIs: `events/{event}/pledges` (+`/{pledge}`, `/cancel`,
  `/fulfillments`, `/fulfillments/{fulfillment}/reverse`, `/summary`), `reports/pledges`,
  `reports/celebrations/{celebration}/pledges`, and pledge import/export under
  `events/{event}/pledges/import/*` and `events/{event}/pledges/export`.
- **Import is preview-first and non-mutating until confirmation.** Matching prefers explicit guest/contact
  identity, then normalized email/phone, then (fallback only) email/phone + currency + exact amount;
  name+amount is never used; ambiguous rows require an explicit decision and are never silently merged.
  Re-import is idempotent (no duplicate pledges; fulfillments deduped). Provides a downloadable error report
  and XLSX template. `App\Services\PledgeLedger` owns balances (`open|partially_fulfilled|fulfilled|
  over_fulfilled`); currency is immutable once fulfillment exists.
- **Pledges operator UI** shipped: `/events/:id/pledges` list (search/filter/pagination) and pledge detail
  with manual create/edit, append-only fulfillment history (record + reversal with confirmation), cancel,
  event summary, celebration roll-up and event XLSX export; amounts are shown as *reported* and never imply
  money collected/verified by Invitation Studio, and never gate admission. The pledge import/export
  **wizard UI** is shipped (`/events/:id/pledges/import`): template download → upload (columns recognised
  automatically) → non-mutating preview (validation errors, matches, duplicates, conflicts, ambiguous
  identities) → explicit create/update/skip decisions for ambiguous rows → explicit confirm → results
  summary (created/updated/skipped/failed/ambiguous) → row-level error report (CSV; XLSX when the PHP `zip`
  extension is present) → event export. Manual column mapping is available only under
  **Advanced: Import a different spreadsheet format** for third-party files.
- **Capabilities are enforced server-side.** `check_in` and `contributions` are implementable but
  **default-off**; enabling an event capability (`settings.capabilities`) is validated. A fail-closed
  `EnsureEventCapability` middleware returns **HTTP 403** for pledge/import routes when `contributions` is
  off, and for check-in/attendance and operator credential routes when `check_in` is off. Frontend hiding is
  not treated as authorization. Event-type `default_capabilities` remain the (configurable, non-wedding-only)
  opt-in mechanism.
- **Capability-aware UI:** Pledges and Check-in navigation/actions are hidden/disabled from the event's
  real `capabilities` data, and opening a disabled feature by direct URL shows a friendly "feature disabled"
  state instead of a raw 403. `check_in` and `contributions` are handled independently. Frontend visibility
  is not authorization — the backend still returns 403. Event-type capability defaults remain configurable
  (non-wedding-only), and an event capability editor is available in the event form.
- Outstanding: browser verification and live-provider integration were not performed; the Celebration
  pledge roll-up links are not per-event capability-gated.
- A guest RSVPs via the public page or API: accept/decline, optional plus-ones, custom questions, and
  capacity enforcement; responses are stored in `rsvps` and mirrored to `guests.status`.

### Delivery architecture
- `InvitationDelivery` records one delivery per channel: `kind` (invitation/reminder), `channel`
  (mail/sms/whatsapp), `status` (`queued`/`sent`/`delivered`/`failed`), provider reference, sanitized
  `error`, JSON `meta`, and timestamps.
- Jobs `SendInvitationJob` / `SendReminderJob` compose and dispatch notifications; `SendDeliveryJob`
  handles explicit retries. Notifications (`InvitationNotification`, `ReminderNotification`) build the
  message content and can be previewed without sending.
- SMS/WhatsApp go through a small transport abstraction (`App\Delivery\Contracts\MessageTransport`):
  `HttpMessageTransport` (the generic provider HTTP adapter) or `SandboxMessageTransport` (local capture,
  no network I/O). Email always uses the Laravel mailer.
- **`sent` means accepted/submitted, not delivered.** `delivered` is only set by a provider status
  callback; the app never claims delivery from a successful HTTP request.
- Provider failures are recorded with sanitized reasons (`network_error`, `provider_error`,
  `sandbox_simulated`, ...); response bodies, headers, credentials and message contents are never stored.
- `DeliveryWebhookController` (`POST /api/v1/webhooks/delivery/{channel}`) is authenticated with a shared
  secret (fails closed when unset), is idempotent, rejects regressions from terminal states, and returns
  no guest data.

### Delivery Lab (operator testing)
- `config/delivery_lab.php` + `App\Support\DeliveryLab` provide a **disabled-by-default** test mode with an
  explicit recipient allowlist.
- The lab page (`/delivery-lab`) and API expose:
  - channel readiness (`configured`, `mode`, `verified: false` — configuration is not verified connectivity),
  - counts (queued/sent/delivered/failed plus simulated/blocked),
  - per-channel previews of the **exact composed message** (rendered email HTML, SMS text + character
    count, WhatsApp text + template note) that never send,
  - safe **test sends** scoped to an event+guest, restricted to allow-listed recipients, with a debounce
    to prevent duplicate clicks.
- In sandbox mode SMS/WhatsApp are captured locally and never contact a provider; email uses the Laravel
  mailer (log/array, or a local Mailpit SMTP capture). Sandbox outcomes are labelled **captured** and
  never counted as real delivery.
- Blocked recipients are recorded as an observable lab result (`status: failed` with `meta.lab.blocked`)
  rather than being mislabelled as sent; the bulk jobs keep the Part-1 rule that a blocked send creates
  no delivery row and does not mark the guest as sent.

### Authentication, authorization and account scoping
- The SPA authenticates with Laravel's session guard using same-origin cookies and CSRF (axios echoes
  `XSRF-TOKEN`). API routes live under `/api/v1` and inherit the `web` middleware group.
- Roles: `admin` (owner) and `staff` (linked to one owner via `owner_id`). Owners manage their own
  resources; staff may view/update their owner's events, guests, celebrations and contacts but cannot
  perform owner-only destructive actions (event/celebration/contact delete) or create events.
- Every query is scoped to `isStaff() ? owner_id : user_id`; policies (`EventPolicy`, `GuestPolicy`,
  `CelebrationPolicy`, `ContactPolicy`) plus explicit same-account checks prevent cross-account access.
- Public invitation access is token-scoped only; the invitation token is never exposed in public
  responses, and `guests.contact_id` is hidden from guest serialization.

## Architecture and technology

- **Backend:** Laravel 11 (`laravel/framework ^11.31`, PHP `^8.2`), MySQL/MariaDB, database-backed
  sessions/queue/cache. Excel/CSV via `spatie/simple-excel` + `openspout`; QR via
  `simplesoftwareio/simple-qrcode`.
- **Frontend:** a standalone React 18 + TypeScript + Vite 6 SPA in `frontend/`. It is served same-origin
  by Laravel: production assets build to `public/app` (`base: /app/`) and `SpaController` + a guarded
  route fallback serve the shell at the site root. Legacy Blade pages remain for auth, profile, audit
  logs, staff and the public `/invite/{token}` flow.
- **API auth:** same-origin session/CSRF (no token library). Write endpoints are rate-limited
  (`admin-write`, `admin-heavy`, `admin-send`); public invitation routes use `invite-token`.
- **Queues/notifications:** `QUEUE_CONNECTION=database`; invitation/reminder/import work runs in queued
  jobs and notifications.
- **Responsibility split (kept deliberately separate):** message *composition* (notifications),
  *transport* (`App\Delivery\*`), *persistence* (`InvitationDelivery`), and *presentation* (Delivery Lab
  UI/API). A future provider can be added behind `MessageTransport` without touching the others.
- **Migrations:** additive and reversible; the contacts/celebrations migrations are additive, and
  `events.celebration_id` / `guests.contact_id` use `nullOnDelete` so deletions detach rather than cascade.

## Setup and development

### Prerequisites
- PHP 8.2+ with common extensions (and `zip` for XLSX export; Imagick is **not** required — QR is SVG).
- Composer 2.x, Node.js + npm, MySQL/MariaDB (or SQLite for a quick start).

### Install
```bash
composer install
cd frontend && npm install && cd ..
```

### Environment
```bash
cp .env.example .env          # Windows: copy .env.example .env
php artisan key:generate
```
Configure the database in `.env` (example — do not commit real credentials):
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=invitation_app_local
DB_USERNAME=root
DB_PASSWORD=
```

### Database
```bash
php artisan migrate --seed
```
The default seeder creates `test@example.com` (see `database/seeders/DatabaseSeeder.php` and the user
factory for the password).

### Run the application (multiple processes)
Run these concurrently in separate terminals:
```bash
php artisan serve                       # Laravel API + public invitation pages (http://127.0.0.1:8000)
cd frontend && npm run dev               # Vite dev server (proxies /api to :8000)
php artisan queue:work                  # processes invitation/reminder/import jobs
```
The frontend always talks to Laravel through `/api` and `/invite`; the Vite dev server proxies `/api`.

### Tests (safe workflow)
Tests run against an isolated database only. `tests/TestCase.php` **refuses to run** unless the effective
configuration is `APP_ENV=testing`, `DB_CONNECTION=mysql`, `DB_DATABASE=invitation_app_testing`, host
`127.0.0.1`/`localhost`, port `3306` (set by `phpunit.xml`).
```bash
# create the dedicated database first, e.g.:
#   mysql -u root -e "CREATE DATABASE invitation_app_testing"
php artisan test
```
**Never point PHPUnit at `invitation_app_local`.** Do not run the production frontend build at the same
time as PHPUnit — `npm run build` empties `public/app`.

### Frontend production build
```bash
cd frontend && npm run build     # tsc --noEmit && vite build -> ../public/app
```

### Optional email capture with Mailpit (local)
Capture outbound email locally instead of sending it:
```env
MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=1025
```
Inspect captured messages in the Mailpit web UI (default `http://127.0.0.1:8025`).

### Enabling the Delivery Lab safely (local testing)
```env
DELIVERY_LAB_ENABLED=true
DELIVERY_LAB_SANDBOX=true
DELIVERY_LAB_RECIPIENTS=you@example.test,+2557XXXXXXXX
# optional: DELIVERY_LAB_SANDBOX_OUTCOME=failed  (exercise failure handling)
```
After changing `.env`, run `php artisan config:clear` (and `php artisan queue:restart` if a worker is
running). Restore/disable these flags after testing.

## Configuration reference

All values below are read from the environment; **never commit real secrets**.

| Variable | Purpose / default |
|---|---|
| `APP_URL` | Base URL used to build absolute invitation links and QR payloads (default `http://localhost`). |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Database (`mysql` or `sqlite`). |
| `QUEUE_CONNECTION` | Queue backend (default `database`). |
| `SESSION_DRIVER`, `CACHE_STORE` | Session/cache stores (default `database`). |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | Mail transport (`log`, `array`, `smtp`, …). |
| `INVITATION_CHANNELS` | Comma list of enabled channels; default `mail`. |
| `INVITE_TOKEN_TTL_DAYS` | Optional invitation token TTL; empty means no TTL beyond the event start. |
| `INVITATIONS_WEBHOOK_SECRET` | Shared secret for provider delivery webhooks; empty disables them (fails closed). |
| `SMS_ENDPOINT`, `SMS_TOKEN`, `SMS_SENDER` | Generic SMS provider HTTP adapter. |
| `WHATSAPP_ENDPOINT`, `WHATSAPP_TOKEN`, `WHATSAPP_SENDER` | Generic WhatsApp provider HTTP adapter. |
| `DELIVERY_LAB_ENABLED` | Master switch for Delivery Lab safeguards; default `false`. |
| `DELIVERY_LAB_RECIPIENTS` | Comma list of allow-listed test recipients (emails/phones). |
| `DELIVERY_LAB_SANDBOX` | Capture SMS/WhatsApp locally with no network I/O; default `false`. |
| `DELIVERY_LAB_SANDBOX_OUTCOME` | Simulated sandbox outcome: `accepted` (default) or `failed`. |
| `DELIVERY_LAB_TEST_SEND_DEBOUNCE` | Seconds between identical operator test sends; default `5`. |

Event types and capability definitions live in `config/event_types.php` and `App\Support\EventCapabilities`;
invitation design templates/fonts live in `config/templates.php`.

## Security and operational notes

- **Authorization:** owners see only their own resources; staff see their owner's. Policies plus explicit
  same-account checks prevent cross-account access (verified by authorization/IDOR tests).
- **Invitation tokens** are secrets that grant RSVP access. They are not exposed in public invitation
  responses, and `guests.contact_id` is hidden from guest serialization.
- **Deletion:** deleting a contact **detaches** its guests (`nullOnDelete`); deleting a celebration
  **detaches** its events; deleting an event soft-deletes it (its guests remain but public access becomes
  unavailable); deleting a guest removes the guest, its RSVP and its deliveries.
- **Status semantics:** `queued` → `sent` (accepted/submitted) → `delivered` (provider-confirmed only), or
  `failed`. `blocked` (lab) and `simulated` (sandbox) are represented via `meta`, not the status enum.
  **Provider acceptance is not proof of delivery.**
- **Sandbox safety:** sandbox mode never falls back to a real provider, never fabricates a provider
  reference, and never counts as real delivery. Test sends require the lab to be enabled and the recipient
  to be allow-listed.
- **Tunnels:** for phone testing, set `APP_URL` to the tunnel HTTPS URL so links and QR codes are
  reachable; use dedicated test events/guests and authorized recipients only; stop the tunnel and restore
  `APP_URL` afterwards. Do not expose administrative routes or secrets.
- **Known limitations:** email “delivery” is local mailer acceptance (no provider confirmation) unless a
  provider/mailer reports status; the webhook uses a shared secret only (no provider signature) because no
  provider is integrated; no exactly-once delivery guarantee (a lost provider response may cause a resend).
- **Multi-worker duplicate-send protection requires a shared cache store.** The invitation, reminder and entry-credential
  jobs take a per-guest atomic cache lock (`Cache::lock`) so concurrent workers cannot send the same message twice. This
  only holds across independent processes/hosts when `CACHE_STORE` is a **shared, lock-capable** store — the project
  defaults to `CACHE_STORE=database` (Laravel's `database` cache store uses the `cache_locks` table created by the default
  cache migration). The `array` store (used by the test suite) and `file` store (per-host) do **not** provide cross-host
  coordination. Locks auto-expire after 60s; keep `CACHE_STORE` consistent across all workers/queue consumers.

### Pledge import (operator workflow)

The wizard at `Pledges → Import pledges` (`/events/:id/pledges/import`) is **template-driven**: download the
official XLSX template, fill it in, upload it, and Invitation Studio recognises the columns automatically —
no manual column mapping is required.

**Official template columns** — the single canonical contract, defined once in
`App\Services\PledgeImporter::FIELDS` and used for template generation, header recognition and validation
(so the template and the importer cannot drift apart):

| Column | Required | Notes |
| --- | --- | --- |
| `pledger_name` | **yes** | Pledger name (free text). |
| `email` | no | Used with phone to match an existing guest/contact. |
| `phone` | no | Used with email to match an existing guest/contact. |
| `amount` | **yes** | Pledged amount as an ordinary number (see below). |
| `contributed_amount` | no | Reported amount already contributed → append-only fulfillment. |
| `currency` | no | ISO-4217 code; falls back to the default-currency precedence below. |
| `method` | no | cash, bank_transfer, mobile_money, cheque or other. |
| `notes` | no | Free-text note stored on the pledge. |
| `reference` | no | Receipt/transaction reference. |
| `guest_id` | no | Explicit event-guest identity (takes precedence over email/phone). |
| `contact_id` | no | Explicit account-contact identity. |
| `received_on` | no | Contribution date (YYYY-MM-DD) for a reported contribution. |

- **Header recognition is automatic and forgiving.** Headers are matched case-insensitively and
  whitespace/punctuation-insensitively, with supported aliases (`email`, `phone`, `amount`, `Pledger Name`,
  `Mobile`, `Currency Code`, …). After upload the UI shows the recognised columns, a read-only sample of the
  extracted values, and any columns that will not be imported. A fresh preview is prepared automatically.
- **Required vs. row-level errors.** A missing required **column** is a single file-level error naming the
  column — the importer never reports "missing value" on every row because a header is absent. A recognised
  column whose **cell** is empty or invalid is a row-level error naming that row.
- **Amounts are ordinary values** (`500000`, `500,000`, `500000.00`, `1234.56`, `USD 100.00`). They are
  converted to integer minor units using the resolved currency's precision; operators are never asked for
  minor units, and meaningful fractional amounts are never silently rounded (they are rejected with a
  row-level error). Negative values are rejected.
- **Currency defaults:** explicit row currency → import-request default → event currency
  (`settings.currency`) → configured application default (`INVITATIONS_PLEDGE_DEFAULT_CURRENCY`, default
  `USD`). A missing currency column never invalidates a row.
- **Contributions & balances:** a reported contributed amount becomes an append-only fulfillment entry; the
  outstanding balance is always derived (`pledged − reported contributions`) and a supplied balance is only
  a consistency check. It never affects admission or check-in.
- **Preview is non-mutating** and required before **Confirm**; a stale preview (after changing a mapping,
  mode or currency) cannot be confirmed, and confirmation re-validates server-side. Nothing is written until
  you confirm.
- **Duplicates / re-import:** matching is conservative (explicit guest/contact → normalized email/phone →
  email/phone + currency + exact amount); a name with a matching amount never merges. Re-importing the same
  file does not duplicate pledges or fulfillments; ambiguous rows require an explicit operator decision.
- **Invalid rows** are listed with the offending field and must be corrected and re-imported; they are never
  imported. A row-level error report is downloadable (CSV, or XLSX when the PHP `zip` extension is present).
- **`pledge_date` is not supported and is reported as such.** Invitation Studio has no pledge-date field
  (only contribution dates map to `received_on`). A `pledge_date` column in an uploaded workbook is listed
  under **Not imported** with a clear reason and is never silently dropped or reinterpreted as a
  contribution date. Adding a first-class pledge date would be a separate, approved schema decision.
- **Advanced mapping** (`Advanced: Import a different spreadsheet format`) is available for third-party
  files whose headers do not match the template; it is collapsed by default and never obstructs the
  template-driven path.
- **Legacy compatibility:** previously supported minor-unit columns (`pledged_amount_minor`,
  `fulfillment_amount_minor`) and older aliases remain accepted and are labelled *(legacy)* in the UI, but
  they are **not** part of the official template.

## Data model and engineering decisions

- **Contacts are reusable and account-scoped; guest details stay event-specific and authoritative.**
  Resolution links a guest to a contact by strong identifiers only (email, then phone); it never merges on
  name and never rewrites guest fields.
- **A Celebration groups events without owning their data.** Each event keeps its own operational records;
  deleting the group detaches members rather than cascading.
- **Event types provide defaults/capabilities rather than restricting the platform.** Types are
  configuration data; capabilities are explicit, validated flags.
- **Invitation access/QR identification is separate from event-day attendance verification.** The QR
  encodes the guest-specific invitation URL; it is not proof of presence, and no check-in exists yet.
- **Delivery composition, transport, persistence and presentation stay separate.** This keeps a future
  provider integration from touching message composition, delivery records or the UI.

## Current status and roadmap

- **Implemented and tested (automated):** the features described above, with a PHPUnit suite covering
  events, guests, contacts, celebrations, invitation access/QR, delivery results, webhooks, retries and
  the Delivery Lab (reported: 305 tests, 1,241 assertions, 0 skipped; TypeScript and production build pass).
- **Implemented, needs manual verification:** Delivery Lab UI flows, live email/SMS/WhatsApp delivery, and
  phone QR scanning via a tunnel.
- **Future work:** live provider integrations, attendance/check-in, contributions/pledges, contact
  merge/unmerge, and browser-level Delivery Lab verification.

See `CHANGELOG.md` for the change history.
