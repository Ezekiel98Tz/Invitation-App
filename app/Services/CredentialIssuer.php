<?php

namespace App\Services;

use App\Models\Guest;
use App\Models\InvitationCredential;
use App\Support\CredentialCode;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Owns the entry-credential lifecycle for a guest.
 *
 * One current credential per guest is enforced by the database (unique
 * nullable `active_key`). Issuance is idempotent and safe under concurrent
 * requests. The RSVP link token is never touched here.
 */
class CredentialIssuer
{
    public function current(Guest $guest): ?InvitationCredential
    {
        return InvitationCredential::query()
            ->where('guest_id', $guest->id)
            ->whereIn('status', [InvitationCredential::STATUS_ACTIVE, InvitationCredential::STATUS_ISSUED])
            ->latest('id')
            ->first();
    }

    public function ensure(Guest $guest): InvitationCredential
    {
        return $this->current($guest) ?? $this->create($guest, null);
    }

    public function activate(Guest $guest): InvitationCredential
    {
        $credential = $this->ensure($guest);

        if ($credential->status !== InvitationCredential::STATUS_ACTIVE) {
            $credential->forceFill([
                'status' => InvitationCredential::STATUS_ACTIVE,
                'activated_at' => now(),
            ])->save();
        }

        return $credential;
    }

    public function revoke(Guest $guest, string $reason = 'revoked'): void
    {
        InvitationCredential::query()
            ->where('guest_id', $guest->id)
            ->whereIn('status', [InvitationCredential::STATUS_ACTIVE, InvitationCredential::STATUS_ISSUED])
            ->update([
                'status' => InvitationCredential::STATUS_REVOKED,
                'revoked_at' => now(),
                'revoke_reason' => $reason,
                'active_key' => null,
            ]);
    }

    /**
     * Revoke the current credential and mint a replacement, preserving lineage
     * via `replaces_id` (the revoked row is retained for audit).
     */
    public function reissue(Guest $guest, string $reason = 'reissued'): InvitationCredential
    {
        return DB::transaction(function () use ($guest, $reason) {
            $previous = $this->current($guest);
            $this->revoke($guest, $reason);

            return $this->create($guest, $previous?->id);
        });
    }

    /**
     * Resolve a credential by its presented short code (hashed lookup).
     */
    public function resolve(string $code): ?InvitationCredential
    {
        return InvitationCredential::query()
            ->where('code_hash', CredentialCode::hash($code))
            ->first();
    }

    private function create(Guest $guest, ?int $replacesId): InvitationCredential
    {
        $code = CredentialCode::generate();
        $accepted = $guest->status === 'accepted';

        try {
            return InvitationCredential::create([
                'guest_id' => $guest->id,
                'event_id' => $guest->event_id,
                'type' => InvitationCredential::TYPE_SHORT_CODE,
                'status' => $accepted ? InvitationCredential::STATUS_ACTIVE : InvitationCredential::STATUS_ISSUED,
                'code_hash' => CredentialCode::hash($code),
                'code_encrypted' => $code,
                'replaces_id' => $replacesId,
                'active_key' => $guest->id,
                'issued_at' => now(),
                'activated_at' => $accepted ? now() : null,
            ]);
        } catch (QueryException $e) {
            // A concurrent request won the unique `active_key` index; reuse it.
            $existing = $this->current($guest);

            if ($existing) {
                return $existing;
            }

            throw $e;
        }
    }
}
