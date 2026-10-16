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

class InvitationNotification extends Notification implements ShouldQueue
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
     * Build the mail representation of the notification.
     *
     * Uses a dedicated markdown view so the HTML body, the plain-text fallback
     * and the Delivery Lab / event preview are all produced from the same
     * template and data (`preview()` renders this exact message).
     */
    public function toMail(?object $notifiable = null): MailMessage
    {
        return (new MailMessage)
            ->subject($this->mailSubject())
            ->markdown('emails.invitations.invitation', $this->mailData($notifiable));
    }

    protected function mailSubject(): string
    {
        return 'You\'re Invited to '.GuestMailContent::subjectLine($this->event->title).' 🎉';
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
     * Compose the SMS body — the single source of truth for the SMS text.
     *
     * Both `toSms()` (real/sandbox delivery) and `preview()` (Delivery Lab and
     * the event invitation preview) call this method, so the previewed text is
     * always byte-for-byte identical to the text handed to the SMS channel.
     *
     * The blank lines are intentional: a heading, the invitation paragraph, and
     * the call-to-action immediately followed by the full unique URL on its own
     * line. The URL is never shortened. A single emoji is used for warmth; note
     * that non-GSM characters (emoji) make the message UCS-2, which reduces the
     * per-segment character budget — see the Delivery Lab character count, which
     * is the exact composed length, not a segment estimate.
     */
    public function smsMessage(?object $notifiable = null): string
    {
        return implode("\n", [
            "You're Invited! 🎉",
            '',
            "You're warmly invited to ".$this->event->title."! We'd love for you to join us on this special occasion.",
            '',
            'View your invitation and RSVP:',
            $this->inviteUrl,
        ]);
    }

    /**
     * Compose the WhatsApp body — the single source of truth shared by
     * `toWhatsApp()` and `preview()`.
     *
     * Uses WhatsApp's own markup (*bold*) rather than HTML, separates greeting,
     * invitation, call to action, URL and closing with blank lines, and keeps
     * the complete URL on its own line so it is easy to tap.
     */
    public function whatsAppMessage(?object $notifiable = null): string
    {
        $lines = [
            "🎉 *You're Invited!*",
            '',
        ];

        $name = GuestMailContent::guestName($notifiable);

        if ($name !== null) {
            $lines[] = 'Hello '.$name.'! ❤️';
            $lines[] = '';
        }

        $lines[] = "We'd be delighted to have you join us for *".$this->event->title."*.";
        $lines[] = '';
        $lines[] = "It's a special occasion, and we'd love to celebrate with you!";
        $lines[] = '';
        $lines[] = '💌 *View your invitation and RSVP:*';
        $lines[] = '';
        $lines[] = $this->inviteUrl;
        $lines[] = '';
        $lines[] = 'We hope to celebrate with you! 🥂';

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
