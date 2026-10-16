<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Bounded page-size resolution for administrative list endpoints.
 *
 * One explicit, shared policy: a client may request `per_page`, but the value is
 * always constrained into [1, MAX]. A missing, non-numeric, zero or negative
 * value falls back to DEFAULT, so a malformed or hostile parameter can never
 * disable pagination or ask for an unbounded page. Page numbers themselves are
 * already clamped to >= 1 by Laravel's paginator resolver.
 *
 * This is the reusable convention for admin tables; adopt it per endpoint rather
 * than rewriting existing controllers wholesale.
 */
final class PageSize
{
    public const DEFAULT = 20;

    public const MAX = 100;

    public static function resolve(Request $request, int $default = self::DEFAULT, int $max = self::MAX): int
    {
        // `input()` reads query-string parameters (GET list endpoints) as well as
        // JSON body parameters (POST preview), so one convention works for both.
        $raw = $request->input('per_page');

        if (! is_numeric($raw)) {
            return $default;
        }

        $value = (int) $raw;

        if ($value < 1) {
            return $default;
        }

        return min($value, $max);
    }
}
