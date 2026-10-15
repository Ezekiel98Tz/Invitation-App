<?php

namespace App\Delivery\Transports;

use App\Delivery\Contracts\MessageTransport;
use App\Delivery\TransportResult;

/**
 * Local sandbox transport for SMS / WhatsApp.
 *
 * Performs NO network I/O and never contacts a provider. It captures the
 * composed message locally (stored on the delivery's `meta`) and returns a
 * controlled simulated outcome. It never fabricates a provider reference id:
 * sandbox results are always labelled `mode = 'sandbox'`.
 */
class SandboxMessageTransport implements MessageTransport
{
    public function send(string $channel, array $payload): TransportResult
    {
        $meta = [
            'sandbox' => true,
            'captured' => [
                'message' => $payload['message'],
            ],
        ];

        if (config('delivery_lab.sandbox_outcome') === 'failed') {
            return TransportResult::failed(
                'sandbox',
                'sandbox_simulated',
                'Simulated provider failure (sandbox).',
                null,
                $meta,
            );
        }

        return TransportResult::accepted('sandbox', null, null, $meta);
    }
}
