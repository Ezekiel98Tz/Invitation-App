<?php

namespace App\Jobs;

use App\Models\Guest;
use App\Models\InvitationDelivery;
use App\Notifications\DeliveryFailedNotification;
use App\Notifications\InvitationNotification;
use App\Support\MailDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
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

        // Idempotency: a duplicate job for an already-sent guest must not resend.
        if ($guest->sent_at !== null) {
            return;
        }

        // Serialise concurrent duplicate dispatches (for example a manual resend
        // racing the scheduler) so two jobs cannot both send before either marks
        // the guest as sent. Released when this attempt finishes; retries and
        // deliberate resends (which reset sent_at) re-acquire it.
        $lock = Cache::lock('invitation:guest:'.$guest->id, 60);

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
     * Compose and send the invitation, recording delivery outcomes. Only marks
     * the guest as sent when a channel actually reached an accepted state.
     */
    private function deliver(Guest $guest): void
    {
        $notification = new InvitationNotification($guest->event, $guest->invite_url_absolute);
        $channels = $notification->via($guest);

        if ($channels === []) {
            // No configured channel is available, or the Delivery Lab blocked
            // this recipient. Do not send and do not mark the guest as notified.
            return;
        }

        // A queue retry reuses the rows created by the first attempt; a fresh
        // operation (initial send or resend) creates new rows.
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

            $delivery = InvitationDelivery::openFor($guest->id, 'invitation', $mapped, $reuseExisting);

            if ($delivery->isCompleted()) {
                // Already accepted/delivered on a previous attempt: never resend.
                continue;
            }

            $pending[] = $mapped;
            $rows[$mapped] = $delivery;
            $notification->setDeliveryId($mapped, $delivery->id);
        }

        if ($pending === []) {
            $guest->forceFill(['sent_at' => now()])->save();

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
                // Sent inline (not re-queued) so SMS/WhatsApp delivery rows reach
                // a terminal state within this job. Otherwise the rows would stay
                // `queued` while a nested notification job is still pending, and
                // `sent_at` would never be set — causing the unsent scheduler to
                // re-dispatch and duplicate the invitation. This also keeps the
                // invitation URL/token out of serialized queued payloads.
                $guest->notifyNow($notification);
            } catch (Throwable $e) {
                // Sanitized: never store raw provider text or message contents.
                InvitationDelivery::where('guest_id', $guest->id)
                    ->where('kind', 'invitation')
                    ->where('status', 'queued')
                    ->update([
                        'status' => 'failed',
                        'failed_at' => now(),
                        'error' => 'The delivery could not be sent.',
                    ]);

                throw $e;
            }
        }

        // Never mark the guest as invited unless at least one channel actually
        // reached an accepted state (queued is not enough). An unavailable or
        // unconfigured transport must not look like a successful invitation.
        if (! $this->anyChannelAccepted($rows)) {
            return;
        }

        $guest->forceFill(['sent_at' => now()])->save();
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

    public function failed(Throwable $exception): void
    {
        $guest = Guest::with(['event.user'])->find($this->guest->id);

        if (! $guest || ! $guest->event || ! $guest->event->user) {
            return;
        }

        $failed = InvitationDelivery::query()
            ->where('guest_id', $guest->id)
            ->where('kind', 'invitation')
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
            'invitation',
            $failed->channel,
            $error
        ));
    }
}
