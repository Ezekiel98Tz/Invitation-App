<?php

namespace App\Notifications;

use App\Models\Event;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\Channels\WhatsAppChannel;
use App\Support\DeliveryLab;
use App\Support\GuestMailContent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Delivers a guest's event-entry code.
 *
 * This is deliberately separate from the invitation/reminder notifications: it
 * carries the entry credential, not the RSVP link. The code is placed in the
 * SMS/WhatsApp payload as `redact_secret` so the sandbox transport can exclude
 * it from captured content — plaintext must never be persisted in delivery meta,
 * logs or previews.
 */
class EntryCredentialNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public array $deliveryIds = [];

    public ?array $onlyChannels = null;

    public function __construct(
        public Event $event,
        public string $code,
    ) {}

    public function setDeliveryId(string $channel, int $deliveryId): void
    {
        $this->deliveryIds[$channel] = $deliveryId;
    }

    public function getDeliveryId(string $channel): ?int
    {
        return $this->deliveryIds[$channel] ?? null;
    }

    public function onlyChannels(array $channels): self
    {
        $this->onlyChannels = array_values(array_unique($channels));

        return $this;
    }

    public function via(object $notifiable): array
    {
        $configured = config('invitations.channels', ['mail']);
        $channels = [];

        foreach ($configured as $channel) {
            if ($this->onlyChannels !== null && ! in_array($channel, $this->onlyChannels, true)) {
                continue;
            }

            if ($channel === 'mail' && $notifiable->routeNotificationForMail()) {
                $channels[] = 'mail';
            }

            if ($channel === 'sms' && $notifiable->routeNotificationForSms()) {
                $channels[] = SmsChannel::class;
            }

            if ($channel === 'whatsapp' && $notifiable->routeNotificationForWhatsApp()) {
                $channels[] = WhatsAppChannel::class;
            }
        }

        return DeliveryLab::filterChannels($notifiable, array_values(array_unique($channels)));
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = GuestMailContent::guestName($notifiable);
        $details = GuestMailContent::eventDetails($this->event);

        $message = (new MailMessage)->subject('Your entry code for '.$this->event->title);

        $message->greeting($name ? 'Dear '.$name.',' : 'Hello,');
        $message->line('Here is your entry code. Please show it at the door:');
        $message->line('**'.$this->code.'**');
        $message->line('Event: '.$this->event->title);

        if ($details['date'] !== null) {
            $message->line('Date: '.$details['date']);
        }

        if ($details['venue'] !== null) {
            $message->line('Venue: '.$details['venue']);
        }

        $message->line('Keep this code private — it is personal to you.');

        return $message;
    }

    public function toSms(object $notifiable): array
    {
        return [
            'to' => $notifiable->routeNotificationForSms(),
            'message' => $this->smsMessage(),
            'delivery_id' => $this->getDeliveryId('sms'),
            'redact_secret' => $this->code,
        ];
    }

    public function toWhatsApp(object $notifiable): array
    {
        return [
            'to' => $notifiable->routeNotificationForWhatsApp(),
            'message' => $this->whatsAppMessage($notifiable),
            'delivery_id' => $this->getDeliveryId('whatsapp'),
            'redact_secret' => $this->code,
        ];
    }

    public function smsMessage(): string
    {
        return implode("\n", [
            'Your entry code for '.$this->event->title.':',
            $this->code,
            '',
            'Show this code at the door.',
            'Keep it private.',
        ]);
    }

    public function whatsAppMessage(object $notifiable): string
    {
        $lines = ['🎟️ *Your entry code*', ''];

        $name = GuestMailContent::guestName($notifiable);

        if ($name !== null) {
            $lines[] = 'Hello '.$name.'!';
            $lines[] = '';
        }

        $lines[] = 'Event: *'.$this->event->title.'*';
        $lines[] = '';
        $lines[] = 'Your entry code:';
        $lines[] = '*'.$this->code.'*';
        $lines[] = '';
        $lines[] = 'Show this code at the door. Keep it private.';

        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event_id' => $this->event->id,
        ];
    }
}
