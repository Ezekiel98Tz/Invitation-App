---
name: Invitation Studio
description: The Host’s Fieldbook — editorial clarity for event operations.
colors:
  primary: "#245E52"
  primary-hover: "#1C4B42"
  primary-soft: "#E7F0EC"
  canvas: "#F4F6F3"
  surface: "#FFFFFF"
  surface-subtle: "#EEF2EE"
  text-primary: "#1F2B28"
  text-secondary: "#586661"
  border: "#DAE1DC"
  focus: "#245E52"
  focus-ring: "rgb(36 94 82 / 28%)"
  disabled: "#53615B"
  disabled-surface: "#E7ECE8"
  success: "#235D3F"
  success-soft: "#EAF3ED"
  success-border: "#C7DDCE"
  warning: "#765000"
  warning-soft: "#FBF2D8"
  warning-border: "#E5D4A2"
  danger: "#943B36"
  danger-hover: "#7E302C"
  danger-soft: "#F8EAE8"
  danger-border: "#E7C5C1"
  danger-on-dark: "#FFC6C1"
  info: "#285C75"
  info-soft: "#EAF2F6"
  info-border: "#C7D9E2"
typography:
  display:
    fontFamily: "'Playfair Display', Georgia, 'Times New Roman', serif"
    fontSize: "2.6rem"
    fontWeight: 500
    lineHeight: 1.2
    letterSpacing: "-0.02em"
  body:
    fontFamily: "'Figtree', system-ui, -apple-system, 'Segoe UI', sans-serif"
    fontSize: "1rem"
    fontWeight: 400
    lineHeight: 1.5
rounded:
  sm: "6px"
  md: "8px"
  lg: "12px"
  pill: "999px"
spacing:
  xs: "4px"
  sm: "8px"
  md: "12px"
  lg: "16px"
  xl: "24px"
  2xl: "32px"
components:
  button-primary:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.surface}"
    rounded: "{rounded.sm}"
    height: "44px"
    padding: "0 16px"
  button-secondary:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.text-primary}"
    rounded: "{rounded.sm}"
    height: "44px"
    padding: "0 16px"
  input:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.text-primary}"
    rounded: "{rounded.sm}"
    height: "44px"
    padding: "8px 11px"
  badge-success:
    backgroundColor: "{colors.success-soft}"
    textColor: "{colors.success}"
    rounded: "{rounded.pill}"
    padding: "2px 8px"
  alert-error:
    backgroundColor: "{colors.danger-soft}"
    textColor: "{colors.danger}"
    rounded: "{rounded.md}"
    padding: "11px 14px"
  card:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.text-primary}"
    rounded: "{rounded.md}"
    padding: "20px"
---

# Design System: Invitation Studio

## Overview

**Creative North Star: “The Host’s Fieldbook”**

This is an operating interface for preparing and running events, not a wedding-only design product. Its character comes from editorial hierarchy applied with operational restraint: precise typography, quiet surfaces, reliable state cues, and a single confident accent. The product’s invitations may carry their own event-specific visual styles; the application chrome remains consistent and task-focused.

Use mineral neutrals and jade without literal paper textures, ornamental flourishes, glass effects, or decorative gradients. Figtree carries the working interface. Playfair Display is reserved for editorial/public headings where its contrast adds meaning, not for dense tables or routine controls.

**Key Characteristics:**
- Considered and composed, without slowing down operational tasks.
- Border-led structure with modest depth only where interaction needs it.
- Status is explicit in text and supported—not replaced—by color.
- Shared semantic tokens serve React and Blade without replacing either styling stack.

## Colors

Mineral surfaces and green-charcoal text support long operational sessions; jade marks primary actions and current focus. Success, warning, danger, and information each use a distinct accessible text/background pair. These status labels remain visible so color is never the only signal.

### Primary
- **Deep Jade:** primary actions, selected controls, active links, and focus indicators.
- **Jade Shade:** hover treatment for primary actions.
- **Jade Wash:** selected and informational surfaces that need primary context without competing with the action.

### Neutral
- **Mineral Canvas:** the page ground.
- **White Surface:** forms, menus, dialogs, and working surfaces.
- **Soft Mineral:** hover rows, neutral states, and disabled surfaces.
- **Green Charcoal:** primary text and high-emphasis content.
- **Quiet Green Gray:** secondary text, helper copy, and supporting metadata.
- **Mineral Line:** dividers and visible control/surface boundaries.

### Status
- **Success:** dark green text on a pale green surface.
- **Warning:** deep ochre text on a pale sand surface.
- **Danger:** deep brick text on a pale rose surface.
- **Information:** deep blue-green text on a pale blue surface.

**The One Accent Rule.** Jade is the only primary brand accent. Status hues are reserved for status and feedback, not scattered decoration.

**Contrast check:** Current defined pairs measure 13.47:1 for primary text on canvas, 6.02:1 for secondary text on white, and 7.51:1 for white on the primary accent. Defined status foreground/background pairs measure at least 6.10:1. Recheck any future color or surface combination rather than relying on these values for unlisted pairings.

## Typography

**Display Font:** Playfair Display (with Georgia/Times fallbacks)
**Body Font:** Figtree (with the system sans-serif stack as fallback)
**Label/Mono Font:** System monospace only for codes and measured numeric data.

**Character:** Playfair Display provides a selective editorial voice on the landing/auth headings. Figtree remains the clear, compact workhorse for navigation, forms, tables, statuses, and operational page headings.

### Hierarchy
- **Display:** medium weight with a restrained negative track; public/editorial headings only.
- **Headline:** Figtree semibold for operational page titles and workflow sections.
- **Title:** Figtree semibold for cards, dialogs, and sub-sections.
- **Body:** Figtree regular with a comfortable 1.5 line height; keep long explanatory copy to a readable measure.
- **Label:** Figtree semibold, sentence case, compact but not faint.

**The Working Type Rule.** Never use the editorial face for dense data, status labels, or form controls. Invitation content remains governed by the invitation design selected for that event.

## Layout

Use a consistent centered content container with a maximum width of 1280px and 24px desktop gutters; reduce the page gutter to 16px on narrow screens. The existing stylesheet uses 900px, 760px, and 640px breakpoints for its current grids and column visibility; later shell/workflow phases may refine those layouts without changing route behavior.

Spacing tokens follow a 4px step with 8px as the working rhythm. Group related fields and actions closely; separate page sections with larger intervals. Do not force every region into a card. Tables may scroll within their own container on narrow screens rather than widening the page.

## Elevation & Depth

The user selected a flat, border-led surface philosophy. Resting content uses surface contrast and a visible hairline border. Shadows are subtle and reserved for an interactive menu, dialog, or a modest hover lift where that feedback is useful; they do not replace boundaries.

**The Quiet Rest Rule.** The interface is flat at rest. Elevate only a layer that must read as floating or interactive.

## Shapes

Corners stay restrained: small controls use a 6px radius, standard panels use 8px, and larger dialog surfaces use 12px. Pill shapes are reserved for compact status/count chips, not used as the default shape for buttons or cards. Borders remain visible on white and mineral surfaces.

## Components

### Buttons
- **Character:** crisp and restrained, with clear sentence-case action labels.
- **Primary:** deep jade fill with white text; 44px control height and compact horizontal padding.
- **Secondary / Ghost:** white or transparent surface with a mineral border and green-charcoal text.
- **Danger:** danger color is reserved for destructive actions, with confirmation for irreversible work.
- **Hover / Focus:** color and border transition; a clear 2px focus indicator. No hover scale or layout shift.
- **Disabled:** use the disabled surface and text tokens; retain the action label and avoid reducing it to an unreadable opacity.

### Chips
- **Style:** compact with a pale semantic surface and a readable, matching foreground.
- **State:** every badge keeps a textual state label; status is not conveyed by color alone.

### Cards / Containers
- **Corner Style:** restrained 8px standard panel, with a visible mineral border.
- **Shadow Strategy:** flat at rest; overlays and meaningful hover states may use the defined soft shadow.
- **Internal Padding:** use the spacing scale and keep table/data surfaces denser than editorial sections.

### Inputs / Fields
- **Style:** white surface, visible mineral border, 6px radius, and a 44px target height.
- **Focus:** jade border and visible outline/ring; focus must remain visible for keyboard users.
- **Error / Disabled:** errors are linked to their control and announced; disabled controls retain legible text and affordance.
- **Labels / Helpers:** labels are programmatically associated with controls; helper text and errors are linked with `aria-describedby`.

### Alerts and Loading
- **Alerts:** semantic `alert`/`status` roles, appropriate live announcements, and a visible text prefix as well as color.
- **Loading:** a named status message accompanies any decorative spinner or skeleton.
- **Reduced motion:** respect `prefers-reduced-motion` and remove nonessential animation.

### Navigation

The React shell uses a persistent, labeled workspace rail with a separate current-event section; the rail may collapse to an icon rail only when each icon retains its accessible name. The top strip carries an event breadcrumb on event routes. Current items use jade, a light selected surface, and `aria-current`, not color alone. Event-tab links use hashes on the existing event URL so Guests, Invitations, and Deliveries remain the same page tabs.

At narrow widths, the React navigation becomes an in-flow panel opened by a labeled menu control. It has a visible close action, closes on Escape and after navigation, and does not obscure the page. Blade-backed account/admin screens use a compact top navigation with the same workspace labels, an in-flow mobile disclosure, and the shared token palette. Staff and Audit Log links are shown in the admin group based on the existing authenticated `is_admin` value; server authorization remains authoritative.

## Do's and Don'ts

### Do:
- **Do** use the shared semantic tokens from `resources/css/design-tokens.css` in both existing CSS pipelines.
- **Do** keep status meaning in the label and use color as reinforcing context.
- **Do** preserve Figtree for operational reading and Playfair Display for selective editorial emphasis.
- **Do** keep the focus indicator visible and honor reduced-motion preferences.
- **Do** use borders and whitespace before reaching for shadows or additional cards.

### Don't:
- **Don't** use a gradient, glass blur, or decorative animation as a substitute for product content.
- **Don't** make every surface a card or every control pill-shaped.
- **Don't** use color alone for RSVP, delivery, check-in, pledge, or import state.
- **Don't** surface pledge balances as payment verification or an admission requirement.
- **Don't** replace event-specific invitation design typography with application chrome styles.
