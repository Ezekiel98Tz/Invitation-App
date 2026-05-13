<?php

namespace App\Notifications;

use App\Models\Event;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\Channels\WhatsAppChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class ReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public array $deliveryIds = [];

    public ?array $onlyChannels = null;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public Event $event,
        public string $inviteUrl,
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

    public function preview(): array
    {
        $when = Carbon::parse($this->event->event_start)->timezone($this->event->timezone);

        return [
            'mail' => [
                'subject' => 'Reminder: '.$this->event->title,
                'lines' => [
                    'This is a reminder to RSVP for '.$this->event->title.'.',
                    'Venue: '.$this->event->venue,
                    'Starts: '.$when->toDayDateTimeString().' ('.$this->event->timezone.')',
                ],
                'action_text' => 'RSVP Now',
                'action_url' => $this->inviteUrl,
            ],
            'sms' => [
                'message' => 'Reminder: RSVP for '.$this->event->title.' '.$this->inviteUrl,
            ],
            'whatsapp' => [
                'message' => 'Reminder: RSVP for '.$this->event->title.' '.$this->inviteUrl,
            ],
        ];
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $configured = config('invitations.channels', ['mail']);

        $channels = [];

        foreach ($configured as $channel) {
            if ($this->onlyChannels !== null && ! in_array($channel, $this->onlyChannels, true)) {
                continue;
            }

            if ($channel === 'mail') {
                $channels[] = 'mail';
            }

            if ($channel === 'sms' && $notifiable->routeNotificationForSms()) {
                $channels[] = SmsChannel::class;
            }

            if ($channel === 'whatsapp' && $notifiable->routeNotificationForWhatsApp()) {
                $channels[] = WhatsAppChannel::class;
            }
        }

        return array_values(array_unique($channels));
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $when = Carbon::parse($this->event->event_start)->timezone($this->event->timezone);

        return (new MailMessage)
            ->subject('Reminder: '.$this->event->title)
            ->greeting('Hello '.$notifiable->name.',')
            ->line('This is a reminder to RSVP for '.$this->event->title.'.')
            ->line('Venue: '.$this->event->venue)
            ->line('Starts: '.$when->toDayDateTimeString().' ('.$this->event->timezone.')')
            ->action('RSVP Now', $this->inviteUrl);
    }

    public function toSms(object $notifiable): array
    {
        return [
            'to' => $notifiable->routeNotificationForSms(),
            'message' => 'Reminder: RSVP for '.$this->event->title.' '.$this->inviteUrl,
            'delivery_id' => $this->getDeliveryId('sms'),
        ];
    }

    public function toWhatsApp(object $notifiable): array
    {
        return [
            'to' => $notifiable->routeNotificationForWhatsApp(),
            'message' => 'Reminder: RSVP for '.$this->event->title.' '.$this->inviteUrl,
            'delivery_id' => $this->getDeliveryId('whatsapp'),
        ];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event_id' => $this->event->id,
            'invite_url' => $this->inviteUrl,
        ];
    }
}
