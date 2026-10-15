<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\InvitationDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Provider delivery-status callbacks.
 *
 * Authenticated with a shared secret (fail-closed when unset). Callbacks are
 * idempotent and monotonic: a repeated callback, or one that would regress a
 * terminal state, is ignored rather than rewriting the record. Responses never
 * contain guest tokens, contact data, or any other personal information.
 */
class DeliveryWebhookController extends Controller
{
    /**
     * Channel statuses accepted from providers, ordered by progress.
     * `delivered` and `failed` are terminal and never regress.
     */
    private const STATUS_RANK = ['queued' => 0, 'sent' => 1, 'delivered' => 2, 'failed' => 3];

    public function store(Request $request, string $channel): JsonResponse
    {
        if (! in_array($channel, ['sms', 'whatsapp', 'mail'], true)) {
            abort(404);
        }

        $secret = (string) config('invitations.webhook_secret');

        // Fail closed: a webhook endpoint with no shared secret is refused
        // rather than left open to unauthenticated status forgery.
        if ($secret === '') {
            return response()->json(['message' => 'Webhook secret is not configured.'], 503);
        }

        $provided = (string) $request->header('X-Webhook-Secret');

        if (! hash_equals($secret, $provided)) {
            Log::warning('Delivery webhook rejected: invalid secret', ['channel' => $channel]);

            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $data = $request->validate([
            'provider_message_id' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(['sent', 'delivered', 'failed'])],
            'error' => ['nullable', 'string', 'max:500'],
            'meta' => ['nullable', 'array'],
        ]);

        $delivery = InvitationDelivery::query()
            ->where('channel', $channel)
            ->where('provider_message_id', $data['provider_message_id'])
            ->orderByDesc('id')
            ->first();

        if (! $delivery) {
            // Safe diagnostic only: no guest data or message contents.
            Log::warning('Delivery webhook ignored: unknown delivery reference', [
                'channel' => $channel,
                'provider_message_id' => $data['provider_message_id'],
            ]);

            return response()->json(['message' => 'Not found'], 404);
        }

        $incoming = $data['status'];

        if (! $this->isForwardTransition($delivery->status, $incoming)) {
            Log::warning('Delivery webhook ignored: non-forward transition', [
                'channel' => $channel,
                'from' => $delivery->status,
                'to' => $incoming,
            ]);

            return response()->json(['updated' => false, 'reason' => 'ignored']);
        }

        $updates = [
            'status' => $incoming,
            'error' => $incoming === 'failed' ? ($data['error'] ?? null) : null,
            'meta' => $data['meta'] ?? $delivery->meta,
        ];

        if ($incoming === 'sent') {
            $updates['sent_at'] = $delivery->sent_at ?? now();
        }

        if ($incoming === 'delivered') {
            $updates['delivered_at'] = $delivery->delivered_at ?? now();
        }

        if ($incoming === 'failed') {
            $updates['failed_at'] = $delivery->failed_at ?? now();
        }

        $delivery->fill($updates)->save();

        return response()->json(['updated' => true, 'status' => $incoming]);
    }

    /**
     * Allow only forward progress. Terminal states never regress, and an
     * unchanged status is an idempotent no-op.
     */
    private function isForwardTransition(string $from, string $to): bool
    {
        if ($from === $to) {
            return false;
        }

        if (in_array($from, ['delivered', 'failed'], true)) {
            return false;
        }

        return (self::STATUS_RANK[$to] ?? -1) > (self::STATUS_RANK[$from] ?? -1);
    }
}
