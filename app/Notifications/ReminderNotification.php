<?php

namespace App\Notifications;

use App\Models\Event;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\Channels\WhatsAppChannel;
use App\Support\DeliveryLab;
use App\Support\GuestMailContent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

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

    public function preview(?object $notifiable = null): array
    {
        $mail = $this->toMail($notifiable);

        return [
            'mail' => [
                'subject' => (string) $mail->subject,
                'html' => (string) $mail->render(),
                'text' => (string) app(Markdown::class)->renderText((string) $mail->markdown, $mail->data()),
                'action_text' => 'View Invitation & RSVP',
                'action_url' => $this->inviteUrl,
            ],
            'sms' => [
                'message' => $this->smsMessage($notifiable),
            ],
            'whatsapp' => [
                'message' => $this->whatsAppMessage($notifiable),
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

    /**
     * Build the mail representation of the reminder.
     *
     * Uses its own markdown view (distinct wording from the invitation) so the
     * HTML body, plain-text fallback and previews all share one template.
     */
    public function toMail(?object $notifiable = null): MailMessage
    {
        return (new MailMessage)
            ->subject($this->mailSubject())
            ->markdown('emails.invitations.reminder', $this->mailData($notifiable));
    }

    protected function mailSubject(): string
    {
        return 'A Friendly Reminder — '.GuestMailContent::subjectLine($this->event->title);
    }

    /**
     * @return array<string, mixed>
     */
    protected function mailData(?object $notifiable): array
    {
        $details = GuestMailContent::eventDetails($this->event);

        return [
            'guestName' => GuestMailContent::guestName($notifiable),
            'eventName' => $this->event->title,
            'date' => $details['date'],
            'venue' => $details['venue'],
            'hasDetails' => $details['hasDetails'],
            'invitationUrl' => $this->inviteUrl,
            'senderName' => GuestMailContent::senderName($this->event),
        ];
    }

    public function toSms(object $notifiable): array
    {
        return [
            'to' => $notifiable->routeNotificationForSms(),
            'message' => $this->smsMessage($notifiable),
            'delivery_id' => $this->getDeliveryId('sms'),
        ];
    }

    public function toWhatsApp(object $notifiable): array
    {
        return [
            'to' => $notifiable->routeNotificationForWhatsApp(),
            'message' => $this->whatsAppMessage($notifiable),
            'delivery_id' => $this->getDeliveryId('whatsapp'),
        ];
    }

    /**
     * Compose the reminder SMS body — the single source of truth for the SMS
     * text, shared by `toSms()` (delivery) and `preview()`.
     *
     * Deliberately worded as a reminder (distinct from the initial invitation),
     * with the same readable spacing and the full unique URL on its own line
     * directly after the call to action. The URL is never shortened.
     */
    public function smsMessage(?object $notifiable = null): string
    {
        return implode("\n", [
            'A Friendly Reminder ⏰',
            '',
            "We're still hoping you can join us for ".$this->event->title.". There's still time to RSVP!",
            '',
            'RSVP here:',
            $this->inviteUrl,
        ]);
    }

    /**
     * Compose the reminder WhatsApp body — the single source of truth shared by
     * `toWhatsApp()` and `preview()`. Reminder-specific wording, WhatsApp
     * *bold* markup, blank-line separation, and the full URL on its own line.
     */
    public function whatsAppMessage(?object $notifiable = null): string
    {
        $lines = [
            '⏰ *A Friendly Reminder*',
            '',
        ];

        $name = GuestMailContent::guestName($notifiable);

        if ($name !== null) {
            $lines[] = 'Hello '.$name.'! 🌸';
            $lines[] = '';
        }

        $lines[] = "We haven't heard from you yet about *".$this->event->title."*.";
        $lines[] = '';
        $lines[] = "There's still time to RSVP, and we'd love to celebrate with you!";
        $lines[] = '';
        $lines[] = '💌 *View your invitation and RSVP:*';
        $lines[] = '';
        $lines[] = $this->inviteUrl;
        $lines[] = '';
        $lines[] = 'Looking forward to your reply! 🥂';

        return implode("\n", $lines);
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
