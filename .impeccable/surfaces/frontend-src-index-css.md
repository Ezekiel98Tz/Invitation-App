---
version: 1
slug: "frontend-src-index-css"
primary_target: "frontend/src/index.css"
related_targets: ["resources/css/design-tokens.css","frontend/src/components/ui.tsx","frontend/src/components/Modal.tsx"]
---

# Application Design Foundation

## Scope

Global product design foundation for the React SPA and existing Blade surfaces. Mode: Operate. This phase implements shared tokens and interaction primitives only; page-level composition and route behavior remain unchanged.

## Confirmed constraints

- Preserve existing product facts, routes, content, functionality, authentication, authorization, API contracts, public invitation access, and workflow rules.
- The user approved the editorial mineral-neutral / deep-jade direction, Figtree for operational UI, and selective Playfair Display for editorial headings.
- React 18/Vite 6/TypeScript 5.6 custom CSS and Laravel Blade/Tailwind 3 remain the implementation architecture. No UI framework or React Bits dependency is introduced.
- Reports is a later React surface backed by existing report APIs only; do not implement it in this foundation phase.

## Direction contract

**THESIS:** Make the operational interface feel editorially considered without asking organizers or staff to learn a new visual grammar for routine event work. Refuse both the generic indigo SaaS starter and wedding-only ornament.

**OWN-WORLD:** Mineral canvas, white working surfaces, green-charcoal text, deep jade as the sole primary accent, Figtree controls/body, and Playfair Display only for editorial headings. Shared semantic tokens feed both existing CSS pipelines. Flat, border-led surfaces; subtle shadows reserved for overlays and hover; 6–12px corners; crisp sentence-case controls with visible focus.

**STORY:** Users should be able to read status, recognize an available action, and understand feedback. The foundation must make controls, errors, and state messages consistent while preserving all existing content and behaviors.

**FIRST VIEWPORT:** No route composition changes in this phase. Existing page structures remain in place; their shared surfaces, type defaults, controls, focus treatment, and state primitives adopt the new tokens. Shell, landing, authentication, and workflow compositions are deferred to later phases.

**FORM:** The user-pinned direction is **The Host’s Fieldbook**: an editorial operating system, not a literal paper texture or wedding motif. The user selected flat, border-led depth and crisp, restrained controls. Impeccable direction seed `52b9e52c` was run; its assignment does not override this explicit approval. None of the dealt concepts displaces the user's product-specific direction.

**FINISH:** unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance
