# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

- **Primary:** Event organizers preparing and running private and corporate events. Supported event kinds also include weddings, birthdays, graduations, celebrations, and other event types.
- **Secondary:** Staff invited by an organizer/admin to help with authorized event operations. Staff are a secondary audience, confirmed for this product record by the user on 16 October 2026.
- **Invitees:** Guests access their invitation through a guest-specific public link and submit or update an RSVP.

## Product Purpose

Invitation Studio helps organizers create and manage events, design and distribute digital invitations, maintain guest records, collect RSVP responses, and operate event-day check-in. Related capabilities include contacts, grouped celebrations, delivery management, reports, and informational pledge records.

## Positioning

The confirmed product mechanism is an event-centered workflow connecting event setup, guest management, invitation design and delivery, RSVP tracking, and—when enabled—event-day entry credentials and check-in. This describes implemented capabilities; it is not a comparative or performance claim.

## Operating Context

- An organizer may group related, independently managed events in a **Celebration**. Grouping does not merge event guests, invitation designs, or RSVPs.
- Event capabilities, including check-in and contributions, are event-specific and may be disabled. Server-side authorization remains authoritative.
- Invitations and RSVP links are guest-specific. Entry credentials are separate from invitation links.
- Delivery supports email, SMS, and WhatsApp paths where configured; Delivery Lab distinguishes sandbox/testing from provider delivery and has safeguards such as recipient allowlists.
- Pledges and fulfillment entries record reported information only. They do not move or verify money and must never determine admission or check-in eligibility.

## Capabilities and Constraints

- The application uses Laravel 11, a MySQL-backed API, a React 18 / TypeScript SPA built with Vite, and existing Blade pages. React owns most product routes; Blade still owns selected public invitation, password recovery/verification, profile, and administration pages.
- Authentication uses the existing cookie/session model and CSRF protections. Preserve authentication, authorization, ownership, policies, event capabilities, API contracts, validation, pagination, queues, notifications, and existing URLs.
- Invitation tokens and entry credential codes are sensitive and have distinct purposes. Do not expose them outside their intended, existing guest/operator actions.
- Check-in lookup is not attendance recording. Recording, override, and reversal behavior must remain explicit and auditable.
- Reports are approved as a future frontend surface. Existing data sources are `/api/v1/reports/events`, `/api/v1/reports/pledges`, `/api/v1/reports/celebrations/{celebration}/pledges`, and event report export at `/api/v1/reports/events/{event}/export`. Use only the actual API fields; do not invent metrics or change the backend to support the UI.
- Product-wide visual work must not replace working features with mockups, fake data, or nonfunctional controls.

## Brand Commitments

- Product name: **Invitation Studio**.
- The user approved an editorial, premium direction with mineral-neutral surfaces, deep jade, green-charcoal text, Figtree for operational UI, and selective Playfair Display for editorial headings. The visual system and exact tokens belong in `DESIGN.md`, not in this product record.

## Evidence on Hand

- The repository contains working React and Blade interfaces, API-backed event and guest workflows, invitation templates, public RSVP flows, and operational/report endpoints.
- The landing page contains a sample invitation preview. Treat sample event content as illustrative, not as a customer, testimonial, or factual product claim.
- No verified customer testimonials, client logos, awards, or usage benchmarks were identified in the reviewed product surfaces. Do not fabricate them.

## Product Principles

1. Keep events operationally independent even when grouped in a Celebration.
2. Show real operational data and make the current state and next action understandable.
3. Preserve role, ownership, and event-capability boundaries; UI visibility is not authorization.
4. Keep invitation/RSVP access distinct from entry credentials and recorded attendance.
5. Present pledges as reported information only, never as payments or admission conditions.

## Accessibility & Inclusion

Target WCAG AA text contrast, keyboard-operable workflows, programmatically associated labels and errors, visible focus, semantic status announcements, responsive narrow-screen layouts, and reduced-motion support. Color must not be the only status signal.
