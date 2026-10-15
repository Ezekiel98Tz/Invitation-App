<?php

namespace App\Delivery\Transports;

use App\Delivery\Contracts\MessageTransport;
use App\Delivery\TransportResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The existing generic HTTP provider adapter (SMS / WhatsApp). Preserves the
 * previous behaviour: POSTs the composed payload to the channel endpoint and
 * only ever captures the provider reference id from a real response.
 */
class HttpMessageTransport implements MessageTransport
{
    public function send(string $channel, array $payload): TransportResult
    {
        $config = (array) config("invitations.$channel", []);
        $endpoint = $config['endpoint'] ?? null;
        $label = $channel === 'whatsapp' ? 'WhatsApp' : 'SMS';

        if (! is_string($endpoint) || $endpoint === '') {
            return TransportResult::failed('provider', 'not_configured', 'The channel is not configured.');
        }

        $request = Http::asJson()->timeout(10);

        if (! empty($config['token'])) {
            $request = $request->withToken($config['token']);
        }

        try {
            $response = $request->post($endpoint, [
                'to' => $payload['to'],
                'message' => $payload['message'],
                'sender' => $config['sender'] ?? null,
            ]);
        } catch (ConnectionException $e) {
            return TransportResult::failed('provider', 'network_error', "The {$label} provider could not be reached.");
        }

        if (! $response->successful()) {
            return TransportResult::failed('provider', 'provider_error', "The {$label} provider returned an error response.", $response->status());
        }

        $referenceId = $response->json('message_id') ?? $response->json('id') ?? null;
        $referenceId = is_scalar($referenceId) ? (string) $referenceId : null;

        return TransportResult::accepted('provider', $referenceId, $response->status());
    }
}
