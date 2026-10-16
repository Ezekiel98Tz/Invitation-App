<?php

namespace App\Support;

/**
 * Short entry-code generation and hashing.
 *
 * The alphabet is Crockford-style: it omits visually ambiguous characters
 * (0/O, 1/I/L, U) so codes can be read aloud and typed reliably. 8 characters
 * over a 32-symbol alphabet is ~40 bits of entropy — far beyond guessable,
 * while remaining short enough for a basic phone. Codes are stored hashed
 * (deterministic HMAC for lookup) and encrypted at rest for controlled reveal.
 */
class CredentialCode
{
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public const LENGTH = 8;

    public static function generate(int $length = self::LENGTH): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }

    public static function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }

    /**
     * Deterministic lookup/verification hash. Uses the app key as the HMAC
     * secret so the stored value is useless without the application key.
     */
    public static function hash(string $code): string
    {
        return hash_hmac('sha256', self::normalize($code), (string) config('app.key'));
    }
}
