<?php

namespace App\Support;

use App\Models\InvitationDelivery;
use Illuminate\Notifications\Notification;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Email delivery outcome handling.
 *
 * Email is the one channel that has no dedicated notification channel class:
 * Laravel's built-in `mail` channel is used directly. This helper runs the mail
 * transport inside the calling job/request (`notifyNow`) so a failure is
 * attributed to the exact delivery attempt and can be retried by the queue,
 * then records the outcome on the InvitationDelivery row using the same
 * accepted/failed conventions as the SMS/WhatsApp channels.
 *
 * Only sanitized, non-secret diagnostics are ever persisted.
 */
class MailDelivery
{
    /**
     * Send the email channel of a notification and record the outcome.
     *
     * The exception is always re-thrown so Laravel's queue retry lifecycle is
     * preserved. On success the row is marked `sent` (transport accepted the
     * message) — never `delivered`, which would require trustworthy provider
     * evidence we do not have.
     */
    public static function send(object $notifiable, Notification $notification, InvitationDelivery $delivery): void
    {
        // Defence in depth: never invoke the transport, and never record
        // success, when there is no usable email destination. The channel gate
        // in the notification's via() normally prevents the row from being
        // created at all; this keeps the helper itself honest if it is ever
        // called directly. The row is left untouched (unattempted).
        if (static::destination($notifiable) === null) {
            return;
        }

        $notification->onlyChannels(['mail']);

        try {
            $notifiable->notifyNow($notification);
        } catch (Throwable $e) {
            static::recordFailure($delivery, $e);

            throw $e;
        }

        $delivery->recordAccepted(null);
    }

    /**
     * The usable email destination for a notifiable, or null when it is
     * missing, empty or whitespace-only.
     */
    public static function destination(object $notifiable): ?string
    {
        if (! method_exists($notifiable, 'routeNotificationForMail')) {
            return null;
        }

        $value = $notifiable->routeNotificationForMail();

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Record a sanitized mail failure without sending.
     */
    public static function recordFailure(InvitationDelivery $delivery, Throwable $e): void
    {
        $delivery->recordFailure(
            static::reason($e),
            static::message($e),
            null,
            static::context($e),
        );
    }

    /**
     * Coarse, stable category used for filtering and display.
     */
    public static function reason(Throwable $e): string
    {
        return $e instanceof TransportExceptionInterface
            ? 'mail_transport_error'
            : 'mail_error';
    }

    /**
     * Operator-facing description. Deliberately generic: the raw exception
     * message is never surfaced because SMTP errors can embed the host,
     * username and, in some libraries, credentials.
     */
    public static function message(Throwable $e): string
    {
        return $e instanceof TransportExceptionInterface
            ? 'The email could not be sent by the configured mail transport.'
            : 'The email could not be prepared or sent.';
    }

    /**
     * Safe, non-secret diagnostics for the delivery metadata: the exception
     * class name, the configured mailer and (only when present) a numeric code.
     *
     * @return array<string, mixed>
     */
    public static function context(Throwable $e): array
    {
        $context = [
            'exception' => $e::class,
            'mailer' => (string) config('mail.default'),
        ];

        $code = $e->getCode();

        if (is_int($code) && $code > 0) {
            $context['code'] = $code;
        }

        return ['provider' => $context];
    }
}
