<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\InvitationDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeliveryWebhookController extends Controller
{
    public function store(Request $request, string $channel): JsonResponse
    {
        if (! in_array($channel, ['sms', 'whatsapp', 'mail'], true)) {
            abort(404);
        }

        $secret = config('invitations.webhook_secret');

        if ($secret) {
            $provided = (string) $request->header('X-Webhook-Secret');

            if (! hash_equals((string) $secret, $provided)) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }
        }

        $data = $request->validate([
            'provider_message_id' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(['sent', 'delivered', 'failed'])],
            'error' => ['nullable', 'string'],
            'meta' => ['nullable', 'array'],
        ]);

        $delivery = InvitationDelivery::query()
            ->where('channel', $channel)
            ->where('provider_message_id', $data['provider_message_id'])
            ->orderByDesc('id')
            ->first();

        if (! $delivery) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $updates = [
            'status' => $data['status'],
            'error' => $data['error'] ?? null,
            'meta' => $data['meta'] ?? $delivery->meta,
        ];

        if ($data['status'] === 'sent') {
            $updates['sent_at'] = now();
        }

        if ($data['status'] === 'delivered') {
            $updates['delivered_at'] = now();
        }

        if ($data['status'] === 'failed') {
            $updates['failed_at'] = now();
        }

        $delivery->fill($updates)->save();

        return response()->json(['updated' => true]);
    }
}
