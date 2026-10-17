---
version: 1
slug: "frontend-src-components-layout-tsx"
primary_target: "frontend/src/components/Layout.tsx"
related_targets: ["frontend/src/pages/EventPage.tsx","frontend/src/index.css","resources/views/layouts/navigation.blade.php","resources/css/app.css"]
---

# Authenticated Shell and Navigation

## Scope

Redesign the authenticated app shell and global/event-context navigation. Mode: Operate. Keep the React route definitions and all Blade-backed account/admin routes. Do not redesign the pages inside the shell.

## Confirmed constraints

- Global React destinations remain `/dashboard` (Events), `/celebrations`, `/contacts`, and `/delivery-lab`.
- Event operations remain the existing event page tabs plus invitation design, check-in, and pledges routes. The event tabs are addressable by in-page hashes only; the route path and backend API remain unchanged.
- React authenticated user data includes `is_admin`, `is_staff`, `role`, and `owner_id`. Use `is_admin` for Staff/Audit navigation visibility; do not use client visibility as server authorization.
- Event navigation uses the existing event read endpoint and `isCapabilityEnabled` helper. Hide check-in and pledges links unless their existing event capability is enabled.
- Profile, Staff, and Audit Log remain Blade routes. Use ordinary server links for them and preserve the existing sign-out mechanisms.
- The Audit Log route/API is currently authenticated and user-scoped rather than server-gated to admins. This shell may follow the user-approved admin-only navigation visibility but must not claim that it changes direct-route authorization.
- Reports is not linked until its later API-backed page is implemented.

## Direction contract

**THESIS:** One steady workspace frame should make global destinations and event-specific tasks easy to distinguish, while the content page remains the focus. Refuse a second competing navigation taxonomy and any icon-only shortcut.

**OWN-WORLD:** Follow **The Host’s Fieldbook**: mineral canvas, white sidebar and panels, green-charcoal text, deep-jade active/focus state, Figtree labels, fine borders, modest radii, and no decorative blur or gradients. Inline SVG line icons accompany visible labels.

**STORY:** Organizers can move from workspace to an event, see which event they are in, and reach its existing guest, invitation, delivery, design, check-in, and pledge tasks. Staff and admin links follow the actual user data and existing capabilities.

**FIRST VIEWPORT:** On desktop, a persistent 256px labeled sidebar anchors the page; a quiet top strip shows workspace context and an event breadcrumb when relevant. On narrow screens, a labeled menu button opens an in-flow navigation panel with an explicit close control, then content continues below without an overlay.

**FORM:** Extend the approved `DESIGN.md` world; seed `52b9e52c` is not a competing visual choice. Keep route paths stable. Existing event-tab paths accept `#guests`, `#invitations`, and `#deliveries` fragments so shell links select the current tab; an empty fragment still defaults to Guests.

**FINISH:** unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance
