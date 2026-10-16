<?php

namespace App\Jobs;

use App\Models\Guest;
use App\Models\InvitationDelivery;
use App\Notifications\DeliveryFailedNotification;
use App\Notifications\ReminderNotification;
use App\Support\MailDelivery;
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
        $rows = [];

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
            $rows[$mapped] = $delivery;
            $notification->setDeliveryId($mapped, $delivery->id);
        }

        if ($pending === []) {
            $guest->forceFill(['reminded_at' => now()])->save();

            return;
        }

        // Email is sent synchronously within this job (never re-queued) so a
        // mail transport failure is recorded against the email attempt and can
        // be retried by the queue. SMS/WhatsApp keep their existing channel
        // behaviour and record their own outcomes.
        if (isset($rows['mail'])) {
            MailDelivery::send($guest, $notification, $rows['mail']);
        }

        $otherChannels = array_values(array_diff($pending, ['mail']));

        if ($otherChannels !== []) {
            $notification->onlyChannels($otherChannels);

            try {
                $guest->notify($notification);
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
            ->whereNotIn('status', ['sent', 'delivered'])
            ->orderByDesc('id')
            ->first();

        if (! $failed) {
            return;
        }

        // Sanitized: never leak raw transport text (it may contain credentials).
        $error = $failed->error ?: MailDelivery::message($exception);

        $guest->event->user->notify(new DeliveryFailedNotification(
            $guest->event,
            $guest,
            'reminder',
            $failed->channel,
            $error
        ));
    }
}
