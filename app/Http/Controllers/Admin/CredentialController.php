<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendEntryCredentialJob;
use App\Models\Event;
use App\Models\Guest;
use App\Models\InvitationCredential;
use App\Services\CredentialIssuer;
use App\Support\AuditLogger;
use App\Support\CredentialAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Operator control of event-entry credentials.
 *
 * The RSVP link token (`guests.invite_token`) is deliberately separate and is
 * never touched here. The credential short code is returned only by the
 * access-controlled issue/reissue/reveal actions and is never persisted to
 * logs, delivery metadata or exports.
 */
class CredentialController extends Controller
{
    public function __construct(private CredentialIssuer $issuer) {}

    public function show(Request $request, Event $event, Guest $guest): JsonResponse
    {
        $this->authorizeGuest($event, $guest);

        return response()->json(['credential' => $this->payload($this->issuer->current($guest))]);
    }

    public function issue(Request $request, Event $event, Guest $guest): JsonResponse
    {
        $this->authorizeGuest($event, $guest);

        $credential = $this->issuer->activate($guest);

        AuditLogger::log($request, 'credential.issue', $credential, [
            'event_id' => $event->id,
            'guest_id' => $guest->id,
        ]);

        return response()->json([
            'credential' => $this->payload($credential),
            'code' => $credential->revealSecret(),
        ], 201);
    }

    public function reissue(Request $request, Event $event, Guest $guest): JsonResponse
    {
        $this->authorizeGuest($event, $guest);

        $credential = $this->issuer->reissue($guest, 'operator_reissue');

        AuditLogger::log($request, 'credential.reissue', $credential, [
            'event_id' => $event->id,
            'guest_id' => $guest->id,
        ]);

        return response()->json([
            'credential' => $this->payload($credential),
            'code' => $credential->revealSecret(),
        ], 201);
    }

    public function revoke(Request $request, Event $event, Guest $guest): JsonResponse
    {
        $this->authorizeGuest($event, $guest);

        $this->issuer->revoke($guest, 'operator_revoked');

        AuditLogger::log($request, 'credential.revoke', $guest, [
            'event_id' => $event->id,
            'guest_id' => $guest->id,
        ]);

        return response()->json(['credential' => $this->payload($this->issuer->current($guest))]);
    }

    /**
     * Access-controlled reveal for the operator to read the code aloud or resend
     * it. Never expose through public responses, logs or exports.
     */
    public function reveal(Request $request, Event $event, Guest $guest): JsonResponse
    {
        $this->authorizeGuest($event, $guest);

        $credential = $this->issuer->current($guest);

        if ($credential === null) {
            abort(404);
        }

        AuditLogger::log($request, 'credential.reveal', $credential, [
            'event_id' => $event->id,
            'guest_id' => $guest->id,
        ]);

        return response()->json([
            'credential' => $this->payload($credential),
            'code' => $credential->revealSecret(),
        ]);
    }

    /**
     * Queue a delivery of the guest's current active credential using the
     * existing delivery architecture. Never mints a new credential.
     */
    public function resend(Request $request, Event $event, Guest $guest): JsonResponse
    {
        $this->authorizeGuest($event, $guest);

        $credential = $this->issuer->current($guest);

        if ($credential === null || ! $credential->isActive()) {
            throw ValidationException::withMessages([
                'credential' => ['There is no active credential to send.'],
            ]);
        }

        SendEntryCredentialJob::dispatch($guest);

        AuditLogger::log($request, 'credential.resend', $credential, [
            'event_id' => $event->id,
            'guest_id' => $guest->id,
        ]);

        return response()->json(['queued' => true], 202);
    }

    private function authorizeGuest(Event $event, Guest $guest): void
    {
        $this->authorize('view', $event);

        if ($guest->event_id !== $event->id) {
            abort(404);
        }

        $this->authorize('view', $guest);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(?InvitationCredential $credential): array
    {
        if ($credential === null) {
            return ['status' => 'none', 'valid' => false, 'reason' => CredentialAccess::NOT_ACTIVE];
        }

        return [
            'id' => $credential->id,
            'type' => $credential->type,
            'status' => $credential->status,
            'issued_at' => $credential->issued_at?->toIso8601String(),
            'activated_at' => $credential->activated_at?->toIso8601String(),
            'revoked_at' => $credential->revoked_at?->toIso8601String(),
            'revoke_reason' => $credential->revoke_reason,
            'replaces_id' => $credential->replaces_id,
            'valid' => CredentialAccess::isUsable($credential),
            'reason' => CredentialAccess::status($credential),
        ];
    }
}
