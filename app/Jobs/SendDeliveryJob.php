<?php

namespace App\Jobs;

use App\Models\Guest;
use App\Models\InvitationDelivery;
use App\Notifications\DeliveryFailedNotification;
use App\Notifications\InvitationNotification;
use App\Notifications\ReminderNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SendDeliveryJob implements ShouldQueue
{
    use Queueable;
    use InteractsWithQueue;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public Guest $guest,
        public string $kind,
        public string $channel,
        public ?int $retryOf = null,
    ) {}

    public function handle(): void
    {
        $guest = Guest::with(['event.user'])->find($this->guest->id);

        if (! $guest || ! $guest->event) {
            return;
        }

        $notification = match ($this->kind) {
            'invitation' => new InvitationNotification($guest->event, $guest->invite_url),
            'reminder' => new ReminderNotification($guest->event, $guest->invite_url),
            default => null,
        };

        if (! $notification) {
            return;
        }

        $notification->onlyChannels([$this->channel]);

        $delivery = InvitationDelivery::create([
            'guest_id' => $guest->id,
            'kind' => $this->kind,
            'channel' => $this->channel,
            'status' => 'queued',
            'meta' => $this->retryOf ? ['retry_of' => $this->retryOf] : null,
        ]);

        $notification->setDeliveryId($this->channel, $delivery->id);

        try {
            $guest->notify($notification);

            if ($this->channel === 'mail') {
                InvitationDelivery::whereKey($delivery->id)->update([
                    'status' => 'sent',
                    'sent_at' => now(),
                ]);
            }
        } catch (Throwable $e) {
            InvitationDelivery::whereKey($delivery->id)->update([
                'status' => 'failed',
                'failed_at' => now(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        $guest = Guest::with(['event.user'])->find($this->guest->id);

        if (! $guest || ! $guest->event || ! $guest->event->user) {
            return;
        }

        $guest->event->user->notify(new DeliveryFailedNotification(
            $guest->event,
            $guest,
            $this->kind,
            $this->channel,
            $exception->getMessage()
        ));
    }
}
