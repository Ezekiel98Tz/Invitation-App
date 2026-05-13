<?php

namespace App\Jobs;

use App\Models\Guest;
use App\Models\InvitationDelivery;
use App\Notifications\DeliveryFailedNotification;
use App\Notifications\InvitationNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SendInvitationJob implements ShouldQueue
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

        $notification = new InvitationNotification($guest->event, $guest->invite_url);
        $channels = $notification->via($guest);

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

            $delivery = InvitationDelivery::create([
                'guest_id' => $guest->id,
                'kind' => 'invitation',
                'channel' => $mapped,
                'status' => 'queued',
            ]);

            $notification->setDeliveryId($mapped, $delivery->id);
        }

        try {
            $guest->notify($notification);

            if (in_array('mail', $channels, true)) {
                $mailId = $notification->getDeliveryId('mail');

                if ($mailId) {
                    InvitationDelivery::whereKey($mailId)->update([
                        'status' => 'sent',
                        'sent_at' => now(),
                    ]);
                }
            }
        } catch (Throwable $e) {
            InvitationDelivery::where('guest_id', $guest->id)
                ->where('kind', 'invitation')
                ->where('status', 'queued')
                ->update([
                    'status' => 'failed',
                    'failed_at' => now(),
                    'error' => $e->getMessage(),
                ]);

            throw $e;
        }

        $guest->forceFill(['sent_at' => now()])->save();
    }

    public function failed(Throwable $exception): void
    {
        $guest = Guest::with(['event.user'])->find($this->guest->id);

        if (! $guest || ! $guest->event || ! $guest->event->user) {
            return;
        }

        $failed = InvitationDelivery::query()
            ->where('guest_id', $guest->id)
            ->where('kind', 'invitation')
            ->where('status', 'queued')
            ->orderByDesc('id')
            ->first();

        if (! $failed) {
            return;
        }

        $guest->event->user->notify(new DeliveryFailedNotification(
            $guest->event,
            $guest,
            'invitation',
            $failed->channel,
            $exception->getMessage()
        ));
    }
}
