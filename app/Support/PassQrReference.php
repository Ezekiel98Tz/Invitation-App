<?php

namespace App\Support;

use App\Models\InvitationCredential;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Opaque, tamper-evident QR reference for one entry-credential row.
 *
 * The QR must never contain the plaintext entry code, the code hash, or a raw
 * database id. The encrypted payload is only a lookup reference: the server
 * still resolves the exact credential, verifies its event, and applies
 * CredentialAccess. Reissuing creates a new credential id, so the previous QR
 * resolves to a revoked row and cannot silently become the new credential.
 */
class PassQrReference
{
    private const PREFIX = 'pass:v1:';

    public static function make(InvitationCredential $credential): string
    {
        $payload = $credential->id.':'.$credential->event_id;

        return self::PREFIX.Crypt::encryptString($payload);
    }

    public static function resolve(string $reference): ?InvitationCredential
    {
        if (! str_starts_with($reference, self::PREFIX)) {
            return null;
        }

        try {
            $payload = Crypt::decryptString(substr($reference, strlen(self::PREFIX)));
        } catch (Throwable) {
            return null;
        }

        if (! preg_match('/^([1-9][0-9]*):([1-9][0-9]*)$/', $payload, $matches)) {
            return null;
        }

        return InvitationCredential::query()
            ->with(['guest.rsvp', 'event'])
            ->whereKey((int) $matches[1])
            ->where('event_id', (int) $matches[2])
            ->first();
    }
}
