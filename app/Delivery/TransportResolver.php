<?php

namespace App\Delivery;

use App\Delivery\Contracts\MessageTransport;
use App\Delivery\Transports\HttpMessageTransport;
use App\Delivery\Transports\SandboxMessageTransport;
use App\Support\DeliveryLab;

/**
 * Chooses the transport for a channel. In sandbox mode, SMS/WhatsApp are
 * captured locally; otherwise the generic HTTP adapter is used. Email never
 * uses this resolver — it always goes through the Laravel mailer.
 */
class TransportResolver
{
    public function forChannel(string $channel): MessageTransport
    {
        if (in_array($channel, ['sms', 'whatsapp'], true) && DeliveryLab::sandbox()) {
            return app(SandboxMessageTransport::class);
        }

        return app(HttpMessageTransport::class);
    }
}
