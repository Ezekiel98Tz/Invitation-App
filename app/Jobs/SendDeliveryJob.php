<?php

namespace App\Jobs;

use App\Models\Guest;
use App\Models\InvitationDelivery;
use App\Notifications\DeliveryFailedNotification;
use App\Notifications\InvitationNotification;
use App\Notifications\ReminderNotification;
use App\Support\MailDelivery;
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
        public ?int $deliveryId = null,
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
            $this->failQueuedRow('The delivery could not be prepared because the message type is unsupported.');

            return;
        }

        $notification->onlyChannels([$this->channel]);

        // Skip when the channel is not configured, or the Delivery Lab blocked it.
        if ($notification->via($guest) === []) {
            $this->failQueuedRow('The selected channel is unavailable for this recipient.');

            return;
        }

        // A queue retry reuses the row created by the first attempt so an
        // explicit retry never creates duplicate records.
        $delivery = null;

        if ($this->deliveryId !== null) {
            $delivery = InvitationDelivery::find($this->deliveryId);

            if (
                $delivery
                && ($delivery->guest_id !== $guest->id
                    || $delivery->kind !== $this->kind
                    || $delivery->channel !== $this->channel)
            ) {
                $delivery = null;
            }
        }

        if (! $delivery && $this->attempts() > 1) {
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

        // Email is sent synchronously so a mail transport failure is recorded
        // against this attempt and can be retried by the queue; the exception is
        // re-thrown. SMS/WhatsApp keep their existing channel behaviour.
        if ($this->channel === 'mail') {
            MailDelivery::send($guest, $notification, $delivery);

            return;
        }

        try {
            $guest->notify($notification);
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

    private function failQueuedRow(string $reason): void
    {
        if ($this->deliveryId === null) {
            return;
        }

        $delivery = InvitationDelivery::find($this->deliveryId);

        if (! $delivery || $delivery->status !== 'queued') {
            return;
        }

        $delivery->forceFill([
            'status' => 'failed',
            'failed_at' => now(),
            'error' => $reason,
        ])->save();
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
            MailDelivery::message($exception)
        ));
    }
}
