<?php

namespace App\Notifications;

use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GuestImportFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Event $event,
        public string $reason,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Guest import failed: '.$this->event->title)
            ->line('Your guest import for "'.$this->event->title.'" could not be completed.')
            ->line($this->reason)
            ->line('You can review the file and try importing again.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'event_id' => $this->event->id,
        ];
    }
}
