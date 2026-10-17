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
use Illuminate\Support\Facades\Cache;
use Throwable;

class SendReminderJob implements ShouldQueue
{
    use Queueable;
    use InteractsWithQueue;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @param  list<string>  $channels  Empty means the configured channel set.
     */
    public function __construct(
        public Guest $guest,
        public array $channels = [],
        public ?int $deliveryId = null,
    ) {}

    public function handle(): void
    {
        $guest = Guest::with(['event.user'])->find($this->guest->id);

        if (! $guest) {
            return;
        }

        if ($guest->status !== 'pending') {
            $this->failQueuedRow('No reminder was sent because this guest is not pending.');

            return;
        }

        if ($guest->reminded_at !== null) {
            $this->failQueuedRow('No reminder was sent because one has already been accepted for this guest.');

            return;
        }

        // Serialise concurrent duplicate scheduler invocations for the same
        // guest so two jobs cannot both send a reminder before either records
        // `reminded_at` (which is what makes a duplicate reminder possible under
        // an asynchronous queue). The lock is released when this attempt
        // finishes, so a genuine retry can re-acquire it.
        $lock = Cache::lock('reminder:guest:'.$guest->id, 60);

        if (! $lock->get()) {
            return;
        }

        try {
            $this->deliver($guest);
        } finally {
            $lock->release();
        }
    }

    /**
     * Compose and send the reminder, recording delivery outcomes. Never marks a
     * reminder as delivered — only that a transport accepted a channel.
     */
    private function deliver(Guest $guest): void
    {
        $notification = new ReminderNotification($guest->event, $guest->invite_url_absolute);

        if ($this->channels !== []) {
            $notification->onlyChannels($this->channels);
        }

        $channels = $notification->via($guest);

        if ($channels === []) {
            $this->failQueuedRow('No reminder was sent because the selected channel is unavailable for this recipient.');

            return;
        }

        // A queue retry reuses the rows created by the first attempt; a
        // deliberate new reminder (reminded_at reset) creates new rows.
        $reuseExisting = $this->attempts() > 1;
        $pending = [];
        $rows = [];
        $precreated = $this->precreatedRow($guest);

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

            $delivery = $precreated && $precreated->channel === $mapped
                ? $precreated
                : InvitationDelivery::openFor($guest->id, 'reminder', $mapped, $reuseExisting);

            if ($delivery->isCompleted()) {
                continue;
            }

            $pending[] = $mapped;
            $rows[$mapped] = $delivery;
            $notification->setDeliveryId($mapped, $delivery->id);
        }

        if ($pending === []) {
            if ($precreated && $precreated->status === 'queued') {
                $this->failQueuedRow('No reminder was sent because the selected channel did not produce a new delivery attempt.');

                return;
            }

            $guest->forceFill(['reminded_at' => now()])->save();

            return;
        }

        // Email is sent synchronously within this job (never re-queued) so a
        // mail transport failure is recorded against the email attempt and can
        // be retried by the queue.
        if (isset($rows['mail'])) {
            MailDelivery::send($guest, $notification, $rows['mail']);
        }

        $otherChannels = array_values(array_diff($pending, ['mail']));

        if ($otherChannels !== []) {
            $notification->onlyChannels($otherChannels);

            try {
                // Sent inline so delivery rows reach a terminal state within
                // this job; this lets `reminded_at` be set correctly under an
                // asynchronous queue instead of leaving the marker null while a
                // nested notification job is still pending.
                $guest->notifyNow($notification);
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

        // Never mark the guest as reminded unless at least one channel actually
        // reached an accepted state (queued is not enough).
        if (! $this->anyChannelAccepted($rows)) {
            return;
        }

        $guest->forceFill(['reminded_at' => now()])->save();
    }

    /**
     * @param  array<string, InvitationDelivery>  $rows
     */
    private function anyChannelAccepted(array $rows): bool
    {
        if ($rows === []) {
            return true;
        }

        return InvitationDelivery::query()
            ->whereIn('id', array_map(static fn (InvitationDelivery $row) => $row->id, $rows))
            ->whereIn('status', ['sent', 'delivered'])
            ->exists();
    }

    private function precreatedRow(Guest $guest): ?InvitationDelivery
    {
        if ($this->deliveryId === null) {
            return null;
        }

        $delivery = InvitationDelivery::find($this->deliveryId);

        return $delivery
            && $delivery->guest_id === $guest->id
            && $delivery->kind === 'reminder'
            && in_array($delivery->channel, $this->channels, true)
                ? $delivery
                : null;
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
