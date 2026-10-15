# Changelog

All notable changes to Invitation Studio are recorded here. The project has not been formally
released yet; the current work is collected under **Unreleased**.

## Unreleased

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
