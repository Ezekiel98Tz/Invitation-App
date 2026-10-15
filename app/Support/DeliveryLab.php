<?php

namespace App\Support;

use App\Notifications\Channels\SmsChannel;
use App\Notifications\Channels\WhatsAppChannel;

/**
 * Delivery Lab test-mode guard.
 *
 * Disabled by default, in which case it is a transparent pass-through and
 * normal delivery is unchanged. When enabled, only the configured allow-list of
 * test recipients may be sent to; every other recipient is blocked. The guard
 * never falls back to live delivery — a blocked recipient is simply not sent
 * to, and is never reported as sent.
 *
 * It is applied both at the notification `via()` boundary (covering the queued
 * jobs) and inside the SMS/WhatsApp channels (defence in depth).
 */
class DeliveryLab
{
    public static function enabled(): bool
    {
        return (bool) config('delivery_lab.enabled', false);
    }

    /**
     * @return list<string>
     */
    public static function recipients(): array
    {
        return array_values((array) config('delivery_lab.recipients', []));
    }

    public static function isAllowed(?string $destination): bool
    {
        if (! static::enabled()) {
            return true;
        }

        if ($destination === null || trim($destination) === '') {
            return false;
        }

        $needle = static::normalize($destination);

        if ($needle === '') {
            return false;
        }

        foreach (static::recipients() as $allowed) {
            if (static::normalize((string) $allowed) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether SMS/WhatsApp are captured locally (no network I/O). Sandbox mode
     * only applies while the lab itself is enabled, so the allowlist is always
     * in force.
     */
    public static function sandbox(): bool
    {
        return static::enabled() && (bool) config('delivery_lab.sandbox', false);
    }

    /**
     * 'disabled' | 'sandbox' | 'provider' — the overall lab mode.
     */
    public static function mode(): string
    {
        if (! static::enabled()) {
            return 'disabled';
        }

        return static::sandbox() ? 'sandbox' : 'provider';
    }

    public static function testSendDebounceSeconds(): int
    {
        return max(0, (int) config('delivery_lab.test_send_debounce_seconds', 5));
    }

    /**
     * Readiness summary per channel. `configured` is configuration presence
     * only — `verified` is always false because no connectivity is proven here.
     *
     * @return array<string, array{configured: bool, mode: string, verified: bool, mailer?: string}>
     */
    public static function channelReadiness(): array
    {
        return [
            'mail' => static::mailReadiness(),
            'sms' => static::httpChannelReadiness('sms'),
            'whatsapp' => static::httpChannelReadiness('whatsapp'),
        ];
    }

    /**
     * @return array{configured: bool, mode: string, verified: bool, mailer: string}
     */
    private static function mailReadiness(): array
    {
        $mailer = (string) config('mail.default', 'log');

        // log/array mailers capture locally and cannot reach a recipient.
        $captured = in_array($mailer, ['log', 'array'], true);

        return [
            'configured' => true,
            'mode' => $captured ? 'sandbox' : 'provider',
            'verified' => false,
            'mailer' => $mailer,
        ];
    }

    /**
     * @return array{configured: bool, mode: string, verified: bool}
     */
    private static function httpChannelReadiness(string $channel): array
    {
        $configured = (string) config("invitations.$channel.endpoint", '') !== '';

        $mode = static::sandbox()
            ? 'sandbox'
            : ($configured ? 'provider' : 'unavailable');

        return [
            'configured' => $configured,
            'mode' => $mode,
            'verified' => false,
        ];
    }

    /**
     * Restrict a notification's channel list to the recipients allowed by the
     * lab. Returns the list unchanged when the lab is disabled.
     *
     * @param  array<int, string>  $channels
     * @return array<int, string>
     */
    public static function filterChannels(object $notifiable, array $channels): array
    {
        if (! static::enabled()) {
            return $channels;
        }

        return array_values(array_filter(
            $channels,
            fn ($channel) => static::channelAllowed($notifiable, $channel)
        ));
    }

    private static function channelAllowed(object $notifiable, string $channel): bool
    {
        return match ($channel) {
            'mail' => static::isAllowed(static::destination($notifiable, 'routeNotificationForMail')),
            SmsChannel::class => static::isAllowed(static::destination($notifiable, 'routeNotificationForSms')),
            WhatsAppChannel::class => static::isAllowed(static::destination($notifiable, 'routeNotificationForWhatsApp')),
            default => true,
        };
    }

    private static function destination(object $notifiable, string $method): ?string
    {
        if (! method_exists($notifiable, $method)) {
            return null;
        }

        $value = $notifiable->{$method}();

        return is_string($value) ? $value : null;
    }

    private static function normalize(string $value): string
    {
        $value = trim($value);

        if (str_contains($value, '@')) {
            return mb_strtolower($value);
        }

        $digits = preg_replace('/\D+/', '', $value);

        return is_string($digits) ? $digits : $value;
    }
}
