<?php

namespace App\Notifications\Channels;

use App\Models\InvitationDelivery;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

class SmsChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toSms')) {
            return;
        }

        $payload = $notification->toSms($notifiable);

        if (! is_array($payload)) {
            return;
        }

        $to = $payload['to'] ?? null;
        $message = $payload['message'] ?? null;

        if (! $to || ! $message) {
            return;
        }

        $endpoint = config('invitations.sms.endpoint');
        $token = config('invitations.sms.token');
        $sender = config('invitations.sms.sender');

        if (! $endpoint) {
            return;
        }

        $request = Http::asJson();

        if ($token) {
            $request = $request->withToken($token);
        }

        $response = $request->post($endpoint, [
            'to' => $to,
            'message' => $message,
            'sender' => $sender,
        ])->throw();

        $deliveryId = $payload['delivery_id'] ?? null;

        if (is_int($deliveryId) || (is_string($deliveryId) && ctype_digit($deliveryId))) {
            $messageId = $response->json('message_id') ?? $response->json('id') ?? null;

            InvitationDelivery::whereKey((int) $deliveryId)->update([
                'status' => 'sent',
                'sent_at' => now(),
                'provider_message_id' => $messageId ? (string) $messageId : null,
            ]);
        }
    }
}
