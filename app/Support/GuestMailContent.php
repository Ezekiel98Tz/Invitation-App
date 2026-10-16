<?php

namespace App\Support;

use App\Models\Event;
use Illuminate\Support\Carbon;

/**
 * Builds the display values shared by the invitation and reminder emails.
 *
 * Everything returned here is plain text intended for Blade's escaped output
 * (`{{ }}`); no value is trusted HTML. Missing data is returned as null so the
 * templates can omit whole lines instead of rendering empty or awkward text
 * such as "Venue: " or "Dear ,".
 */
class GuestMailContent
{
    /**
     * Resolve a usable guest name, or null when it is missing or blank.
     */
    public static function guestName(?object $notifiable): ?string
    {
        if ($notifiable === null || ! isset($notifiable->name)) {
            return null;
        }

        $name = trim((string) $notifiable->name);

        return $name === '' ? null : $name;
    }

    /**
     * Resolve the event details that are meaningful to show, omitting any that
     * are not present.
     *
     * @return array{date: ?string, venue: ?string, hasDetails: bool}
     */
    public static function eventDetails(Event $event): array
    {
        $venue = trim((string) ($event->venue ?? ''));

        $date = null;

        if ($event->event_start !== null) {
            $timezone = trim((string) ($event->timezone ?? ''));

            $when = Carbon::parse($event->event_start);

            if ($timezone !== '') {
                $when = $when->timezone($timezone);
            }

            $date = $when->toDayDateTimeString();

            if ($timezone !== '') {
                $date .= ' ('.$timezone.')';
            }
        }

        return [
            'date' => $date,
            'venue' => $venue === '' ? null : $venue,
            'hasDetails' => $date !== null || $venue !== '',
        ];
    }

    /**
     * The host/sender to sign the email with: the event owner when known,
     * otherwise the configured mail "from" name, otherwise the application
     * name. Never invents a person.
     */
    public static function senderName(Event $event): string
    {
        $owner = trim((string) ($event->user?->name ?? ''));

        if ($owner !== '') {
            return $owner;
        }

        $fromName = trim((string) config('mail.from.name', ''));

        if ($fromName !== '') {
            return $fromName;
        }

        return (string) config('app.name');
    }

    /**
     * A subject line safe to place in a mail header: any line breaks from
     * user-provided titles are collapsed to spaces.
     */
    public static function subjectLine(string $text): string
    {
        return trim((string) preg_replace('/[\r\n]+/', ' ', $text));
    }
}
