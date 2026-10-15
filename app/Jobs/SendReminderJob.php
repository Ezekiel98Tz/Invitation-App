<?php

namespace App\Jobs;

use App\Models\Guest;
use App\Models\InvitationDelivery;
use App\Notifications\DeliveryFailedNotification;
use App\Notifications\ReminderNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SendReminderJob implements ShouldQueue
{
    use Queueable;
    use InteractsWithQueue;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public Guest $guest) {}

    public function handle(): void
    {
        $guest = Guest::with(['event.user'])->find($this->guest->id);

        if (! $guest) {
            return;
        }

        if ($guest->status !== 'pending') {
            return;
        }

        if ($guest->reminded_at !== null) {
            return;
        }

        $notification = new ReminderNotification($guest->event, $guest->invite_url_absolute);
        $channels = $notification->via($guest);

        if ($channels === []) {
            // No configured channel is available, or the Delivery Lab blocked
            // this recipient. Do not send and do not mark the guest as reminded.
            return;
        }

        // A queue retry reuses the rows created by the first attempt; a
        // deliberate new reminder (reminded_at reset) creates new rows.
        $reuseExisting = $this->attempts() > 1;
        $pending = [];

        foreach ($channels as $channel) {
            $mapped = match ($channel) {
                'mail' => 'mail',
                \App\Notifications\Channels\SmsChannel::class => 'sms',
                \App\Notifications\Channels\WhatsAppChannel::class => 'whatsapp',
                default => null,
            };

            if (! $mapped) {
                continue;
            }

            $delivery = InvitationDelivery::openFor($guest->id, 'reminder', $mapped, $reuseExisting);

            if ($delivery->isCompleted()) {
                continue;
            }

            $pending[] = $mapped;
            $notification->setDeliveryId($mapped, $delivery->id);
        }

        if ($pending === []) {
            $guest->forceFill(['reminded_at' => now()])->save();

            return;
        }

        $notification->onlyChannels($pending);

        try {
            $guest->notify($notification);

            if (in_array('mail', $pending, true)) {
                $mailId = $notification->getDeliveryId('mail');

                if ($mailId) {
                    InvitationDelivery::whereKey($mailId)->update([
                        'status' => 'sent',
                        'sent_at' => now(),
                        'error' => null,
                    ]);
                }
            }
        } catch (Throwable $e) {
            // Sanitized: never store raw provider text or message contents.
            InvitationDelivery::where('guest_id', $guest->id)
                ->where('kind', 'reminder')
                ->where('status', 'queued')
                ->update([
                    'status' => 'failed',
                    'failed_at' => now(),
                    'error' => 'The reminder could not be sent.',
                ]);

            throw $e;
        }

        $guest->forceFill(['reminded_at' => now()])->save();
    }

    public function failed(Throwable $exception): void
    {
        $guest = Guest::with(['event.user'])->find($this->guest->id);

        if (! $guest || ! $guest->event || ! $guest->event->user) {
            return;
        }

        $failed = InvitationDelivery::query()
            ->where('guest_id', $guest->id)
            ->where('kind', 'reminder')
            ->where('status', 'queued')
            ->orderByDesc('id')
            ->first();

        if (! $failed) {
            return;
        }

        $guest->event->user->notify(new DeliveryFailedNotification(
            $guest->event,
            $guest,
            'reminder',
            $failed->channel,
            $exception->getMessage()
        ));
    }
}
