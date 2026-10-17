<?php

namespace App\Support;

use App\Models\Event;
use App\Models\InvitationCredential;
use Illuminate\Support\Carbon;

/**
 * Whether an entry credential is usable at the door.
 *
 * Deliberately independent of {@see InvitationAccess}: the RSVP link expires
 * when the event starts, but the entry credential is valid in a window around
 * the event. States are explicit so the UI can distinguish reasons.
 */
class CredentialAccess
{
    public const VALID = 'valid';
    public const NOT_ACTIVE = 'not_active';
    public const REVOKED = 'revoked';
    public const EXPIRED = 'expired';
    public const UNAVAILABLE = 'unavailable';
    public const NOT_YET = 'not_yet';

    public static function isUsable(?InvitationCredential $credential): bool
    {
        return static::status($credential) === self::VALID;
    }

    public static function status(?InvitationCredential $credential): string
    {
        if ($credential === null) {
            return self::NOT_ACTIVE;
        }

        if ($credential->status === InvitationCredential::STATUS_REVOKED) {
            return self::REVOKED;
        }

        if ($credential->status === InvitationCredential::STATUS_EXPIRED) {
            return self::EXPIRED;
        }

        if (! in_array($credential->status, [InvitationCredential::STATUS_ACTIVE, InvitationCredential::STATUS_ISSUED], true)) {
            return self::NOT_ACTIVE;
        }

        $event = $credential->relationLoaded('event') ? $credential->event : $credential->event()->first();

        if ($event === null) {
            return self::UNAVAILABLE;
        }

        $now = Carbon::now($event->timezone);
        ['from' => $from, 'until' => $until] = static::window($event);

        if ($now->lessThan($from)) {
            return self::NOT_YET;
        }

        if ($now->greaterThan($until)) {
            return self::EXPIRED;
        }

        return self::VALID;
    }

    /**
     * @return array{from: Carbon, until: Carbon}
     */
    public static function window(Event $event): array
    {
        $start = Carbon::parse($event->event_start)->timezone($event->timezone ?? config('app.timezone'));

        return [
            'from' => $start->copy()->subHours((int) config('invitations.credential_valid_before_hours', 6)),
            'until' => $start->copy()->addHours((int) config('invitations.credential_valid_after_hours', 12)),
        ];
    }
}
