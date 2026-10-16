import type { CapabilityKey, EventCapabilities, EventItem } from './types';

/**
 * Capability-aware presentation helpers.
 *
 * An event exposes its resolved capability map as the `capabilities` object on
 * the event payload (a derived attribute, also mirrored in `settings`). Every
 * capability is opt-in and defaults to disabled, so an absent key means "off".
 *
 * This is presentation only and must never be treated as authorization: the
 * server enforces capabilities on the API and still returns 403 when a disabled
 * feature is called directly. Callers keep handling that 403.
 */

/** Human labels for capability keys. Mirrors `App\Support\EventCapabilities`. */
export const CAPABILITY_LABELS: Record<CapabilityKey, string> = {
    custom_questions: 'Custom RSVP questions',
    plus_ones: 'Plus-ones',
    waitlist: 'Waitlist',
    check_in: 'Check-in',
    households: 'Households',
    contributions: 'Contributions',
};

/** The effective capability map for an event, tolerating a missing payload. */
export function eventCapabilityMap(event: EventItem | null | undefined): EventCapabilities {
    if (!event) return {};
    return event.capabilities ?? event.settings?.capabilities ?? {};
}

/** Whether a capability is enabled for an event (default-off when unknown). */
export function isCapabilityEnabled(
    event: EventItem | null | undefined,
    key: CapabilityKey,
): boolean {
    return eventCapabilityMap(event)[key] === true;
}
