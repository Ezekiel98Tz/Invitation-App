<?php

namespace App\Notifications;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StaffInviteNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $ownerName,
        public string $acceptUrl,
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
            ->subject('You have been invited as staff')
            ->line($this->ownerName.' invited you to join as staff.')
            ->action('Accept Invitation', $this->acceptUrl);
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'owner_name' => $this->ownerName,
            'accept_url' => $this->acceptUrl,
        ];
    }
}
