<?php

namespace App\Support;

/**
 * Canonical normalization for contact matching keys.
 *
 * This is the single source of truth shared by the ContactResolver service and
 * the contacts backfill command, so the two can never drift.
 *
 * Email: trim surrounding whitespace, lowercase; empty means "unavailable".
 * Phone: keep digits only (dropping punctuation, spaces and a leading "+").
 *        Fewer than MIN_PHONE_DIGITS digits is unusable. Leading zeros are
 *        preserved and national/international formats are deliberately NOT
 *        equated (that requires locale knowledge and would risk unsafe matches).
 */
class ContactNormalizer
{
    public const MIN_PHONE_DIGITS = 7;

    public static function email(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $value = mb_strtolower(trim($email));

        return $value === '' ? null : $value;
    }

    public static function phone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);

        if (! is_string($digits) || $digits === '' || strlen($digits) < self::MIN_PHONE_DIGITS) {
            return null;
        }

        return $digits;
    }
}
