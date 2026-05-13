<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Http\Request;

class AuditLogger
{
    public static function log(Request $request, string $action, mixed $auditable = null, array $meta = []): void
    {
        $user = $request->user();

        if (! $user) {
            return;
        }

        ActivityLog::create([
            'user_id' => $user->id,
            'action' => $action,
            'auditable_type' => $auditable ? get_class($auditable) : null,
            'auditable_id' => $auditable?->id,
            'meta' => $meta ?: null,
            'ip' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ]);
    }
}
