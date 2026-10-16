<?php

namespace App\Notifications\Channels;

use App\Delivery\TransportResolver;
use App\Models\InvitationDelivery;
use App\Support\DeliveryLab;
use Illuminate\Notifications\Notification;
use RuntimeException;

class WhatsAppChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toWhatsApp')) {
            return;
        }

        $payload = $notification->toWhatsApp($notifiable);

        if (! is_array($payload)) {
            return;
        }

        $to = $payload['to'] ?? null;
        $message = $payload['message'] ?? null;

        if (! $to || ! $message) {
            return;
        }

        // Delivery Lab guard: never send to a non-allow-listed test recipient.
        if (! DeliveryLab::isAllowed($to)) {
            return;
        }

        $delivery = $this->deliveryRow($payload['delivery_id'] ?? null);

        $result = app(TransportResolver::class)->forChannel('whatsapp')->send('whatsapp', [
            'to' => $to,
            'message' => $message,
            'sender' => config('invitations.whatsapp.sender'),
            'redact_secret' => is_string($payload['redact_secret'] ?? null) ? $payload['redact_secret'] : null,
        ]);

        if ($result->accepted) {
            $delivery?->recordAccepted($result->referenceId, $result->httpStatus, $result->meta);

            return;
        }

        if ($result->reason === 'not_configured') {
            // The channel applies to this guest but has no provider endpoint.
            // Record it so the attempt is inspectable and is never mistaken for
            // a successful send (do not throw: a retry cannot fix configuration).
            $delivery?->recordFailure('not_configured', 'The WhatsApp channel is not configured.');

            return;
        }

        $delivery?->recordFailure(
            $result->reason ?? 'provider_error',
            $result->error ?? 'The message could not be sent.',
            $result->httpStatus,
            $result->meta,
        );

        throw new RuntimeException('Message delivery failed.');
    }

    private function deliveryRow(mixed $deliveryId): ?InvitationDelivery
    {
        if (! (is_int($deliveryId) || (is_string($deliveryId) && ctype_digit($deliveryId)))) {
            return null;
        }

        return InvitationDelivery::find((int) $deliveryId);
    }
}
