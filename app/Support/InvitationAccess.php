<?php

namespace App\Support;

use App\Models\Event;
use App\Models\Guest;
use Illuminate\Support\Carbon;

/**
 * Single source of truth for whether a guest's public invitation is reachable.
 *
 * "unavailable": the guest has no resolvable event (for example the event was
 *                deleted / soft-deleted).
 * "expired":     the event has already started, or the optional token TTL has
 *                elapsed since the guest was created.
 * "available":   everything else.
 *
 * Both the public invitation endpoints and the operator verification endpoint
 * resolve the rule through this class so the two can never drift.
 */
class InvitationAccess
{
    public const AVAILABLE = 'available';
    public const UNAVAILABLE = 'unavailable';
    public const EXPIRED = 'expired';

    public static function event(Guest $guest): ?Event
    {
        if (! $guest->relationLoaded('event')) {
            $guest->load('event');
        }

        return $guest->event;
    }

    public static function isUnavailable(Guest $guest): bool
    {
        return static::event($guest) === null;
    }

    /**
     * Preserves the existing product rule: an invitation expires once the event
     * has started, or once the configured token TTL has elapsed.
     */
    public static function isExpired(Guest $guest): bool
    {
        $event = static::event($guest);

        if ($event !== null) {
            $now = Carbon::now($event->timezone);
            $eventStart = Carbon::parse($event->event_start)->timezone($event->timezone);

            if ($now->greaterThanOrEqualTo($eventStart)) {
                return true;
            }
        }

        $ttl = config('invitations.token_ttl_days');

        if ($ttl === null || $ttl === '') {
            return false;
        }

        $days = (int) $ttl;

        if ($days <= 0) {
            return false;
        }

        return $guest->created_at !== null
            && $guest->created_at->lt(Carbon::now()->subDays($days));
    }

    /**
     * @return self::AVAILABLE|self::UNAVAILABLE|self::EXPIRED
     */
    public static function status(Guest $guest): string
    {
        if (static::isUnavailable($guest)) {
            return self::UNAVAILABLE;
        }

        if (static::isExpired($guest)) {
            return self::EXPIRED;
        }

        return self::AVAILABLE;
    }
}
