<?php

namespace App\Services;

use App\Exceptions\CommunicationDispatchException;
use App\Jobs\SendDeliveryJob;
use App\Jobs\SendInvitationJob;
use App\Jobs\SendReminderJob;
use App\Models\Guest;
use App\Models\InvitationDelivery;
use App\Support\ContactNormalizer;
use App\Support\DeliveryLab;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Channel-aware queueing for invitation and reminder operations.
 *
 * This service deliberately sits in front of the existing SendInvitationJob and
 * SendReminderJob rather than introducing another transport or queue path. It:
 *  - exposes only channels that are enabled and configured in this environment;
 *  - validates the recipient destination and Delivery Lab restrictions;
 *  - creates the queued delivery row before dispatch so the operation is
 *    visible in Delivery History even while no worker has processed it;
 *  - debounces duplicate submissions while the existing job locks and delivery
 *    idempotency remain the final protection against double sends.
 */
class GuestCommunication
{
    public const CHANNELS = ['mail', 'sms', 'whatsapp'];

    public const KINDS = ['invitation', 'reminder'];

    public const DUPLICATE_WINDOW_SECONDS = 5;

    /** @var array{channels: list<array{key: string, label: string, available: bool, configured: bool, mode: string, reason: ?string, note: ?string}>, delivery_lab_restricted: bool}|null */
    private ?array $channelOptionsCache = null;

    /**
     * @return array{channels: list<array{key: string, label: string, available: bool, configured: bool, mode: string, reason: ?string, note: ?string}>, delivery_lab_restricted: bool}
     */
    public function channelOptions(): array
    {
        if ($this->channelOptionsCache !== null) {
            return $this->channelOptionsCache;
        }

        $configured = array_values((array) config('invitations.channels', []));
        $readiness = DeliveryLab::channelReadiness();
        $labRestricted = DeliveryLab::enabled();

        $channels = [];

        foreach (self::CHANNELS as $channel) {
            $isConfigured = in_array($channel, $configured, true);
            $hasProvider = $channel === 'mail'
                || (string) config("invitations.$channel.endpoint", '') !== '';
            $available = $isConfigured && $hasProvider;
            $mode = (string) ($readiness[$channel]['mode'] ?? 'unavailable');
            $label = match ($channel) {
                'mail' => 'Email',
                'sms' => 'SMS',
                default => 'WhatsApp',
            };

            $reason = null;
            if (! $isConfigured) {
                $reason = 'This channel is not enabled for guest messages in this environment.';
            } elseif (! $hasProvider) {
                $reason = $label.' has no provider endpoint configured.';
            }

            $note = null;
            if ($available && $channel === 'mail' && $mode === 'sandbox') {
                $note = 'Captured locally by the configured mailer; no external email leaves this environment.';
            } elseif ($available && $channel !== 'mail' && $mode === 'sandbox') {
                $note = 'Captured by the Delivery Lab sandbox; no external message leaves this environment.';
            } elseif ($available) {
                $note = 'Queued through the configured provider. Acceptance is not proof of delivery.';
            }

            $channels[] = [
                'key' => $channel,
                'label' => $label,
                'available' => $available,
                'configured' => $isConfigured,
                'mode' => $available ? $mode : 'unavailable',
                'reason' => $reason,
                'note' => $note,
            ];
        }

        return $this->channelOptionsCache = [
            'channels' => $channels,
            'delivery_lab_restricted' => $labRestricted,
        ];
    }

    /**
     * Why this guest cannot receive the selected operation, or null when it can.
     * Reasons are safe for operator display: they never include a destination,
     * invitation token, provider response or credential.
     */
    public function rejectionReason(
        Guest $guest,
        string $kind,
        string $channel,
        bool $individual = false,
    ): ?string {
        if (! in_array($kind, self::KINDS, true) || ! in_array($channel, self::CHANNELS, true)) {
            return 'This message type or channel is not supported.';
        }

        $option = collect($this->channelOptions()['channels'])->firstWhere('key', $channel);
        if (! $option || ! $option['available']) {
            return (string) ($option['reason'] ?? 'This communication channel is unavailable.');
        }

        $destination = $this->destination($guest, $channel);
        if ($destination === null) {
            return match ($channel) {
                'mail' => 'This guest has no usable email address.',
                'sms' => 'This guest has no usable phone number for SMS.',
                default => 'This guest has no usable phone number for WhatsApp.',
            };
        }

        if (! DeliveryLab::isAllowed($destination)) {
            return 'The Delivery Lab restricts this recipient. Add them to the test allowlist before sending.';
        }

        if ($kind === 'invitation' && ! $individual && $guest->sent_at !== null) {
            return 'This guest already has a provider-accepted invitation. Use Resend invitation to send a new attempt.';
        }

        if ($kind === 'reminder') {
            if ($guest->status !== 'pending') {
                return 'This guest is not eligible for a reminder.';
            }

            if ($guest->reminded_at !== null) {
                return 'A reminder has already been accepted for this guest.';
            }

            if (! $individual) {
                if ($guest->sent_at === null) {
                    return 'This guest has no provider-accepted invitation to follow up on.';
                }

                if ($guest->sent_at->gt(now()->subDays(2))) {
                    return 'The invitation was accepted less than two days ago.';
                }
            }
        }

        return null;
    }

    /**
     * Validate, record and dispatch one selected-channel operation.
     *
     * A fresh queued delivery row is created before the job is dispatched. If an
     * identical queued operation already exists it is reused, so retrying after
     * a page reload does not accumulate duplicate queued rows.
     *
     * @return array{queued: bool, reason: ?string, delivery: ?InvitationDelivery}
     */
    public function queue(
        Guest $guest,
        string $kind,
        string $channel,
        bool $individual = false,
    ): array {
        $reason = $this->rejectionReason($guest, $kind, $channel, $individual);

        if ($reason !== null) {
            return ['queued' => false, 'reason' => $reason, 'delivery' => null];
        }

        $debounceKey = sprintf(
            'communication:dispatch:%d:%d:%s:%s',
            $guest->id,
            $guest->created_at?->getTimestamp() ?? 0,
            $kind,
            $channel,
        );

        if (! Cache::add($debounceKey, true, now()->addSeconds(self::DUPLICATE_WINDOW_SECONDS))) {
            return [
                'queued' => false,
                'reason' => 'A send for this guest was just requested. Please wait a moment before trying again.',
                'delivery' => null,
            ];
        }

        // A deliberate resend starts a new invitation attempt. This happens only
        // after validation and the duplicate guard, so a rejected request never
        // clears the existing provider-accepted marker.
        if ($kind === 'invitation' && $individual) {
            $guest->forceFill(['sent_at' => null])->save();
        }

        $delivery = InvitationDelivery::query()
            ->where('guest_id', $guest->id)
            ->where('kind', $kind)
            ->where('channel', $channel)
            ->where('status', 'queued')
            ->latest('id')
            ->first();

        if (! $delivery) {
            $delivery = InvitationDelivery::create([
                'guest_id' => $guest->id,
                'kind' => $kind,
                'channel' => $channel,
                'status' => 'queued',
            ]);
        }

        $job = $kind === 'invitation'
            ? new SendInvitationJob($guest, [$channel], $delivery->id)
            : new SendReminderJob($guest, [$channel], $delivery->id);

        $this->dispatchOrFail($job, $delivery);

        return ['queued' => true, 'reason' => null, 'delivery' => $delivery];
    }

    /**
     * Queue a retry of one failed delivery as a new, traceable attempt.
     *
     * @return array{queued: bool, reason: ?string, delivery: ?InvitationDelivery}
     */
    public function retry(InvitationDelivery $failed): array
    {
        if ($failed->status !== 'failed') {
            return [
                'queued' => false,
                'reason' => 'Only failed delivery attempts can be retried.',
                'delivery' => null,
            ];
        }

        $failed->loadMissing('guest');
        $guest = $failed->guest;

        if (! $guest) {
            return [
                'queued' => false,
                'reason' => 'The guest for this delivery is no longer available.',
                'delivery' => null,
            ];
        }

        $reason = $this->rejectionReason($guest, $failed->kind, $failed->channel, individual: true);

        if ($reason !== null) {
            return ['queued' => false, 'reason' => $reason, 'delivery' => null];
        }

        $key = sprintf(
            'communication:retry:%d:%d',
            $failed->id,
            $failed->updated_at?->getTimestamp() ?? 0,
        );

        if (! Cache::add($key, true, now()->addSeconds(self::DUPLICATE_WINDOW_SECONDS))) {
            return [
                'queued' => false,
                'reason' => 'A retry for this delivery was just requested. Please wait a moment before trying again.',
                'delivery' => null,
            ];
        }

        $delivery = InvitationDelivery::create([
            'guest_id' => $guest->id,
            'kind' => $failed->kind,
            'channel' => $failed->channel,
            'status' => 'queued',
            'meta' => ['retry_of' => $failed->id],
        ]);

        $this->dispatchOrFail(new SendDeliveryJob(
            $guest,
            $failed->kind,
            $failed->channel,
            $failed->id,
            $delivery->id,
        ), $delivery);

        return ['queued' => true, 'reason' => null, 'delivery' => $delivery];
    }

    /**
     * A queue failure must not leave an apparently pending delivery without a
     * job. The failed row is retained as evidence and the raw queue exception is
     * never logged because database queue errors may include payload bindings.
     */
    private function dispatchOrFail(ShouldQueue $job, InvitationDelivery $delivery): void
    {
        try {
            dispatch($job);
        } catch (Throwable $exception) {
            $delivery->forceFill([
                'status' => 'failed',
                'failed_at' => now(),
                'error' => 'The message could not be queued. No delivery was attempted.',
            ])->save();

            Log::warning('Guest communication queue dispatch failed.', [
                'delivery_id' => $delivery->id,
                'exception' => $exception::class,
            ]);

            throw new CommunicationDispatchException('The message could not be queued.');
        }
    }

    public function destination(Guest $guest, string $channel): ?string
    {
        return match ($channel) {
            'mail' => $this->usableEmail($guest->routeNotificationForMail()),
            'sms' => $this->usablePhone($guest->routeNotificationForSms()),
            'whatsapp' => $this->usablePhone($guest->routeNotificationForWhatsApp()),
            default => null,
        };
    }

    private function usableEmail(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) !== false
            ? $value
            : null;
    }

    private function usablePhone(?string $value): ?string
    {
        $number = ContactNormalizer::phone($value);

        return $number !== null && $number !== '' ? $value : null;
    }
}
