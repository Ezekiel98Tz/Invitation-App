<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Guest;
use App\Models\InvitationCredential;
use App\Support\CredentialAccess;
use App\Support\PassQrReference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * Guest-facing event pass.
 *
 * Access uses the existing random, guest-specific RSVP bearer token, but this is
 * a separate surface from RSVP. The response exposes only event display data,
 * the guest's name and a credential-state summary. It never exposes contact
 * details, ids, credential hashes, encrypted codes or the plaintext short code.
 */
class PublicPassController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $guest = $this->guest($token);

        if (! $guest) {
            return $this->notFound();
        }

        ['state' => $state, 'credential' => $credential, 'event' => $event] = $this->resolve($guest);

        $window = $event ? CredentialAccess::window($event) : null;

        return response()->json([
            'guest' => ['name' => $guest->name],
            'event' => $event === null ? null : [
                'title' => $event->title,
                'event_start' => $event->event_start?->toIso8601String(),
                'timezone' => $event->timezone,
                'venue' => $event->venue,
            ],
            'pass' => [
                'state' => $state,
                'credential_status' => $credential?->status,
                // The client builds the QR URL from the pass route it already
                // holds. Echoing the bearer token from JSON would only widen its
                // exposure through reports and client logs.
                'qr_available' => $state === 'valid',
                'valid_from' => $window === null ? null : $window['from']->toIso8601String(),
                'valid_until' => $window === null ? null : $window['until']->toIso8601String(),
                'entry_code_note' => 'If the QR cannot be scanned, show staff the separate entry code sent to you.',
            ],
        ])->withHeaders([
            'Cache-Control' => 'no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    public function qr(string $token): Response|JsonResponse
    {
        $guest = $this->guest($token);

        if (! $guest) {
            return $this->notFound();
        }

        ['state' => $state, 'credential' => $credential] = $this->resolve($guest);

        if ($state !== 'valid' || $credential === null) {
            return $this->notFound();
        }

        $svg = QrCode::format('svg')
            ->size(480)
            ->margin(1)
            ->generate(PassQrReference::make($credential));

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    private function guest(string $token): ?Guest
    {
        return Guest::query()
            ->with(['event', 'rsvp'])
            ->where('invite_token', $token)
            ->first();
    }

    /**
     * @return array{state: string, credential: ?InvitationCredential, event: ?Event}
     */
    private function resolve(Guest $guest): array
    {
        $event = $guest->event;

        if ($event === null) {
            return ['state' => 'unavailable', 'credential' => null, 'event' => null];
        }

        if (($event->capabilities['check_in'] ?? false) !== true) {
            return ['state' => 'disabled', 'credential' => null, 'event' => $event];
        }

        if ($guest->status === 'pending') {
            return ['state' => 'pending', 'credential' => null, 'event' => $event];
        }

        if ($guest->status === 'declined') {
            return ['state' => 'declined', 'credential' => null, 'event' => $event];
        }

        $credential = InvitationCredential::query()
            ->where('guest_id', $guest->id)
            ->latest('id')
            ->first();

        if ($credential === null) {
            return ['state' => 'not_active', 'credential' => null, 'event' => $event];
        }

        $credential->setRelation('event', $event);
        $credential->setRelation('guest', $guest);

        $state = match (CredentialAccess::status($credential)) {
            CredentialAccess::VALID => 'valid',
            CredentialAccess::NOT_YET => 'not_yet',
            CredentialAccess::EXPIRED => 'expired',
            CredentialAccess::REVOKED => 'revoked',
            default => 'not_active',
        };

        return ['state' => $state, 'credential' => $credential, 'event' => $event];
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Event pass not found.'], 404)
            ->withHeaders([
                'Cache-Control' => 'no-store, max-age=0',
                'Referrer-Policy' => 'no-referrer',
                'X-Robots-Tag' => 'noindex, nofollow',
            ]);
    }
}
