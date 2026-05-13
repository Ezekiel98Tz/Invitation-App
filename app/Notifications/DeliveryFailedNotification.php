<?php

namespace App\Notifications;

use App\Models\Event;
use App\Models\Guest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DeliveryFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Event $event,
        public Guest $guest,
        public string $kind,
        public string $channel,
        public string $error,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Delivery failed: '.$this->event->title)
            ->line('Event: '.$this->event->title)
            ->line('Guest: '.$this->guest->name)
            ->line('Kind: '.$this->kind)
            ->line('Channel: '.$this->channel)
            ->line('Error: '.$this->error);
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
            'guest_id' => $this->guest->id,
            'kind' => $this->kind,
            'channel' => $this->channel,
            'error' => $this->error,
        ];
    }
}
