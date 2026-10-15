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
            'invitation' => new InvitationNotification($guest->event, $guest->invite_url_absolute),
            'reminder' => new ReminderNotification($guest->event, $guest->invite_url_absolute),
            default => null,
        };

        if (! $notification) {
            return;
        }

        $notification->onlyChannels([$this->channel]);

        // Skip when the channel is not configured, or the Delivery Lab blocked it.
        if ($notification->via($guest) === []) {
            return;
        }

        // A queue retry reuses the row created by the first attempt so an
        // explicit retry never creates duplicate records.
        $delivery = null;

        if ($this->attempts() > 1) {
            $delivery = InvitationDelivery::query()
                ->where('guest_id', $guest->id)
                ->where('kind', $this->kind)
                ->where('channel', $this->channel)
                ->latest('id')
                ->first();
        }

        if (! $delivery) {
            $delivery = InvitationDelivery::create([
                'guest_id' => $guest->id,
                'kind' => $this->kind,
                'channel' => $this->channel,
                'status' => 'queued',
                'meta' => $this->retryOf ? ['retry_of' => $this->retryOf] : null,
            ]);
        }

        if ($delivery->isCompleted()) {
            // Already accepted/delivered: never resend a completed delivery.
            return;
        }

        $notification->setDeliveryId($this->channel, $delivery->id);

        try {
            $guest->notify($notification);

            if ($this->channel === 'mail') {
                $delivery->forceFill(['status' => 'sent', 'sent_at' => now(), 'error' => null])->save();
            }
        } catch (Throwable $e) {
            // Sanitized: never store raw provider text or message contents.
            $delivery->forceFill([
                'status' => 'failed',
                'failed_at' => now(),
                'error' => 'The delivery could not be sent.',
            ])->save();

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
