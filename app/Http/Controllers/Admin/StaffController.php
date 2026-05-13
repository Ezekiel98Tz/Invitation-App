<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StaffInvite;
use App\Models\User;
use App\Notifications\StaffInviteNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StaffController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->isAdmin()) {
            abort(403);
        }

        $staff = User::query()
            ->where('owner_id', $user->id)
            ->orderBy('id')
            ->paginate(20);

        $invites = StaffInvite::query()
            ->where('owner_id', $user->id)
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json([
            'staff' => $staff,
            'invites' => $invites,
        ]);
    }

    public function invite(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->isAdmin()) {
            abort(403);
        }

        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $email = strtolower($data['email']);

        if ($email === strtolower((string) $user->email)) {
            throw ValidationException::withMessages(['email' => ['You cannot invite yourself.']]);
        }

        $existing = User::query()->where('email', $email)->first();

        if ($existing && $existing->events()->exists()) {
            throw ValidationException::withMessages(['email' => ['This user already owns events and cannot be invited as staff.']]);
        }

        if ($existing && $existing->staff()->exists()) {
            throw ValidationException::withMessages(['email' => ['This user already manages staff and cannot be invited as staff.']]);
        }

        if ($existing && $existing->owner_id !== null && $existing->owner_id !== $user->id) {
            throw ValidationException::withMessages(['email' => ['This user is already staff for another admin.']]);
        }

        $invite = StaffInvite::create([
            'owner_id' => $user->id,
            'email' => $email,
            'token' => Str::random(64),
            'expires_at' => now()->addDays(7),
        ]);

        $acceptUrl = route('staff-invites.accept', ['token' => $invite->token]);

        Notification::route('mail', $email)->notify(new StaffInviteNotification($user->name, $acceptUrl));

        return response()->json(['queued' => true], 201);
    }
}
