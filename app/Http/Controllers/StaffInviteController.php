<?php

namespace App\Http\Controllers;

use App\Models\StaffInvite;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StaffInviteController extends Controller
{
    public function accept(Request $request, string $token): JsonResponse
    {
        $invite = StaffInvite::query()->where('token', $token)->firstOrFail();

        if ($invite->accepted_at !== null) {
            return response()->json(['message' => 'Invite already accepted'], 410);
        }

        if ($invite->expires_at !== null && now()->greaterThan($invite->expires_at)) {
            abort(404);
        }

        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if (strtolower((string) $user->email) !== strtolower($invite->email)) {
            abort(403);
        }

        if ($user->isAdmin() && ($user->events()->exists() || $user->staff()->exists())) {
            throw ValidationException::withMessages([
                'email' => ['This account owns resources and cannot be converted to staff.'],
            ]);
        }

        $user->forceFill([
            'role' => 'staff',
            'owner_id' => $invite->owner_id,
        ])->save();

        $invite->forceFill(['accepted_at' => now()])->save();

        return response()->json(['accepted' => true]);
    }
}
