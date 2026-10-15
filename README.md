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
- Public access is token-scoped and rate-limited. Validity is centralised in `App\Support\InvitationAccess`:
  **unavailable** (event missing / soft-deleted) and **expired** (event already started, or older than
  the optional token TTL) both return a clean `410` (browser page / JSON) or `404` for QR — never a 500.
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
