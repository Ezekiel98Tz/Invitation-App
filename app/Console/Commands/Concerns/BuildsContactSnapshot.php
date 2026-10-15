<?php

namespace App\Console\Commands\Concerns;

use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\Guest;
use App\Models\GuestImport;
use App\Models\InvitationDelivery;
use App\Models\Rsvp;

/**
 * Read-only snapshot and integrity helpers shared by the contacts backfill and
 * verification commands.
 *
 * The snapshot deliberately EXCLUDES `guests.contact_id`: linking guests to
 * contacts is the one change the backfill is allowed to make, so the guest
 * field fingerprint (which must stay identical) is computed from the
 * operational fields only. Everything else — guest ids, tokens, RSVPs,
 * deliveries, guest imports and activity logs, including their references — is
 * fingerprinted so that any unrelated change is detected.
 *
 * Each entity is folded with one JSON-encoded canonical line per row followed
 * by a newline, so free-text fields (error messages, user agents, meta) cannot
 * collide across rows. Everything here is read-only.
 */
trait BuildsContactSnapshot
{
    /**
     * @return array<string, int|string>
     */
    protected function contactSnapshot(): array
    {
        $guestCount = 0;
        $linked = 0;
        $unlinked = 0;
        $guestIdHash = hash_init('sha256');
        $tokenHash = hash_init('sha256');
        $fieldHash = hash_init('sha256');

        Guest::query()
            ->select(['id', 'name', 'email', 'phone', 'status', 'sent_at', 'reminded_at', 'invite_token', 'contact_id'])
            ->orderBy('id')
            ->cursor()
            ->each(function (Guest $guest) use (&$guestCount, &$linked, &$unlinked, &$guestIdHash, &$tokenHash, &$fieldHash) {
                $guestCount++;
                $guest->contact_id === null ? $unlinked++ : $linked++;

                hash_update($guestIdHash, $this->fold([$guest->id]));
                hash_update($tokenHash, $this->fold([$guest->id, $guest->invite_token]));
                hash_update($fieldHash, $this->fold([
                    $guest->id,
                    $guest->name,
                    $guest->email,
                    $guest->phone,
                    $guest->status,
                    $guest->sent_at?->toDateTimeString(),
                    $guest->reminded_at?->toDateTimeString(),
                ]));
            });

        $rsvpCount = 0;
        $rsvpHash = hash_init('sha256');

        Rsvp::query()
            ->select(['id', 'guest_id', 'attending_count', 'answers'])
            ->orderBy('id')
            ->cursor()
            ->each(function (Rsvp $rsvp) use (&$rsvpCount, &$rsvpHash) {
                $rsvpCount++;
                hash_update($rsvpHash, $this->fold([
                    $rsvp->id,
                    $rsvp->guest_id,
                    $rsvp->attending_count,
                    $rsvp->answers,
                ]));
            });

        $deliveryCount = 0;
        $deliveryHash = hash_init('sha256');

        InvitationDelivery::query()
            ->select(['id', 'guest_id', 'kind', 'channel', 'status', 'provider_message_id', 'sent_at', 'delivered_at', 'failed_at'])
            ->orderBy('id')
            ->cursor()
            ->each(function (InvitationDelivery $delivery) use (&$deliveryCount, &$deliveryHash) {
                $deliveryCount++;
                hash_update($deliveryHash, $this->fold([
                    $delivery->id,
                    $delivery->guest_id,
                    $delivery->kind,
                    $delivery->channel,
                    $delivery->status,
                    $delivery->provider_message_id,
                    $delivery->sent_at?->toDateTimeString(),
                    $delivery->delivered_at?->toDateTimeString(),
                    $delivery->failed_at?->toDateTimeString(),
                ]));
            });

        $importCount = 0;
        $importHash = hash_init('sha256');

        GuestImport::query()
            ->select(['id', 'event_id', 'user_id', 'original_filename', 'status', 'created_count', 'skipped_count', 'failed_count', 'error', 'started_at', 'finished_at'])
            ->orderBy('id')
            ->cursor()
            ->each(function (GuestImport $import) use (&$importCount, &$importHash) {
                $importCount++;
                hash_update($importHash, $this->fold([
                    $import->id,
                    $import->event_id,
                    $import->user_id,
                    $import->original_filename,
                    $import->status,
                    $import->created_count,
                    $import->skipped_count,
                    $import->failed_count,
                    $import->error,
                    $import->started_at?->toDateTimeString(),
                    $import->finished_at?->toDateTimeString(),
                ]));
            });

        $activityCount = 0;
        $activityHash = hash_init('sha256');

        ActivityLog::query()
            ->select(['id', 'user_id', 'action', 'auditable_type', 'auditable_id', 'meta', 'ip', 'user_agent', 'created_at'])
            ->orderBy('id')
            ->cursor()
            ->each(function (ActivityLog $log) use (&$activityCount, &$activityHash) {
                $activityCount++;
                hash_update($activityHash, $this->fold([
                    $log->id,
                    $log->user_id,
                    $log->action,
                    // Relevant reference: the auditable morph target.
                    $log->auditable_type,
                    $log->auditable_id,
                    $log->meta,
                    $log->ip,
                    $log->user_agent,
                    $log->created_at?->toDateTimeString(),
                ]));
            });

        return [
            'guest_count' => $guestCount,
            'guest_id_fingerprint' => hash_final($guestIdHash),
            'token_fingerprint' => hash_final($tokenHash),
            'guest_field_fingerprint' => hash_final($fieldHash),
            'linked_guests' => $linked,
            'unlinked_guests' => $unlinked,
            'contact_count' => Contact::query()->count(),
            'rsvp_count' => $rsvpCount,
            'rsvp_fingerprint' => hash_final($rsvpHash),
            'delivery_count' => $deliveryCount,
            'delivery_fingerprint' => hash_final($deliveryHash),
            'guest_import_count' => $importCount,
            'guest_import_fingerprint' => hash_final($importHash),
            'activity_log_count' => $activityCount,
            'activity_log_fingerprint' => hash_final($activityHash),
        ];
    }

    /**
     * Referential / isolation integrity counters. Any non-zero value is a
     * problem the verify command reports.
     *
     * @return array<string, int>
     */
    protected function contactIntegrity(): array
    {
        return [
            'guests_with_missing_contact' => Guest::query()
                ->whereNotNull('contact_id')
                ->whereDoesntHave('contact')
                ->count(),
            'guests_with_missing_event' => Guest::query()
                ->whereDoesntHave('event')
                ->count(),
            'cross_account_links' => Guest::query()
                ->join('events', 'events.id', '=', 'guests.event_id')
                ->join('contacts', 'contacts.id', '=', 'guests.contact_id')
                ->whereColumn('contacts.user_id', '!=', 'events.user_id')
                ->count(),
            'rsvps_with_missing_guest' => Rsvp::query()->whereDoesntHave('guest')->count(),
            'deliveries_with_missing_guest' => InvitationDelivery::query()->whereDoesntHave('guest')->count(),
        ];
    }

    /**
     * Canonical, unambiguous line for one row: a JSON array of the row's
     * identity/reference fields plus a trailing newline.
     *
     * @param  array<int, mixed>  $fields
     */
    private function fold(array $fields): string
    {
        return json_encode($fields, JSON_UNESCAPED_SLASHES)."\n";
    }
}
