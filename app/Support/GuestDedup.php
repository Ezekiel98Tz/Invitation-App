<?php

namespace App\Support;

use App\Models\Event;

/**
 * Single source of truth for guest duplicate detection.
 *
 * Policy: within one event, a guest is a duplicate when it shares the same
 * email (case-insensitive). When no email is supplied, the trimmed name
 * (case-insensitive) is used instead. This is applied consistently by both
 * manual creation and file imports.
 */
class GuestDedup
{
    public static function key(string $name, ?string $email): string
    {
        $email = mb_strtolower(trim((string) $email));

        return $email !== '' ? 'email:'.$email : 'name:'.mb_strtolower(trim($name));
    }

    public static function exists(Event $event, string $name, ?string $email, ?int $exceptGuestId = null): bool
    {
        $query = $event->guests();

        if ($exceptGuestId !== null) {
            $query->where('id', '!=', $exceptGuestId);
        }

        $normalizedEmail = mb_strtolower(trim((string) $email));

        if ($normalizedEmail !== '') {
            $query->whereRaw('LOWER(email) = ?', [$normalizedEmail]);
        } else {
            $query->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))]);
        }

        return $query->exists();
    }

    /**
     * Build the set of duplicate keys for every guest already in the event.
     *
     * @return array<string, true>
     */
    public static function existingKeys(Event $event): array
    {
        $keys = [];

        foreach ($event->guests()->select('name', 'email')->cursor() as $guest) {
            $keys[static::key($guest->name, $guest->email)] = true;
        }

        return $keys;
    }
}
