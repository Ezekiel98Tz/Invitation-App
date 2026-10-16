<?php

namespace App\Jobs;

use App\Models\Guest;
use App\Models\InvitationDelivery;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\EntryCredentialNotification;
use App\Services\CredentialIssuer;
use App\Support\MailDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Sends the guest's current active entry credential.
 *
 * Reuses the existing delivery architecture (per-channel delivery rows, inline
 * email via MailDelivery, SMS/WhatsApp via their channels). Never issues a new
 * credential: if there is no active credential it does nothing. The plaintext
 * code is only ever passed in-memory to the notification.
 */
class SendEntryCredentialJob implements ShouldQueue
{
    use Queueable;
    use InteractsWithQueue;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public Guest $guest) {}

    public function handle(): void
    {
        $guest = Guest::with(['event.user', 'rsvp'])->find($this->guest->id);

        if (! $guest || ! $guest->event) {
            return;
        }

        $credential = app(CredentialIssuer::class)->current($guest);

        if ($credential === null || ! $credential->isActive()) {
            return;
        }

        $code = $credential->revealSecret();

        if (! is_string($code) || $code === '') {
            return;
        }

        // Serialise concurrent duplicate sends (for example a double-click or
        // two operators) for the same guest, matching SendInvitationJob /
        // SendReminderJob. Released when this attempt finishes; retries
        // re-acquire. A failed acquisition is a safe no-op.
        $lock = Cache::lock('credential:guest:'.$guest->id, 60);

        if (! $lock->get()) {
            return;
        }

        try {
            $this->deliver($guest, $code);
        } finally {
            $lock->release();
        }
    }

    private function deliver(Guest $guest, string $code): void
    {
        $notification = new EntryCredentialNotification($guest->event, $code);
        $channels = $notification->via($guest);

        if ($channels === []) {
            return;
        }

        $reuseExisting = $this->attempts() > 1;
        $pending = [];
        $rows = [];

        foreach ($channels as $channel) {
            $mapped = match ($channel) {
                'mail' => 'mail',
                SmsChannel::class => 'sms',
                WhatsAppChannel::class => 'whatsapp',
                default => null,
            };

            if (! $mapped) {
                continue;
            }

            $delivery = InvitationDelivery::openFor($guest->id, 'credential', $mapped, $reuseExisting);

            if ($delivery->isCompleted()) {
                continue;
            }

            $pending[] = $mapped;
            $rows[$mapped] = $delivery;
            $notification->setDeliveryId($mapped, $delivery->id);
        }

        if ($pending === []) {
            return;
        }

        if (isset($rows['mail'])) {
            MailDelivery::send($guest, $notification, $rows['mail']);
        }

        $otherChannels = array_values(array_diff($pending, ['mail']));

        if ($otherChannels !== []) {
            $notification->onlyChannels($otherChannels);
            // Sent inline (not re-queued) so the plaintext entry code is never
            // serialized into the queue or failed-job payloads. The job itself
            // (which carries only the guest id) owns retries.
            $guest->notifyNow($notification);
        }
    }
}
