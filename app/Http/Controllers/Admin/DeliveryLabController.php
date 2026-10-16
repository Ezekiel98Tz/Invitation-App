<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Guest;
use App\Models\InvitationDelivery;
use App\Notifications\InvitationNotification;
use App\Notifications\ReminderNotification;
use App\Support\DeliveryLab;
use App\Support\MailDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Operator-facing Delivery Lab.
 *
 * Lets the operator inspect channel readiness, preview the exact composed
 * invitation/reminder for each channel (without sending), and perform safe
 * sandbox test sends to allow-listed recipients. It never reveals secrets, and
 * it distinguishes simulated (sandbox) results from real provider states.
 */
class DeliveryLabController extends Controller
{
    private const CHANNELS = ['mail', 'sms', 'whatsapp'];
    private const KINDS = ['invitation', 'reminder'];

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Event::class);

        $ownerId = $this->ownerId($request);

        $base = InvitationDelivery::query()
            ->whereHas('guest.event', fn ($q) => $q->where('user_id', $ownerId));

        $statusCounts = (clone $base)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $recent = (clone $base)
            ->with(['guest:id,event_id,name,email,phone', 'guest.event:id,title'])
            ->orderByDesc('id')
            ->limit(15)
            ->get()
            ->map(fn (InvitationDelivery $delivery) => $this->deliveryPayload($delivery));

        return response()->json([
            'lab' => [
                'enabled' => DeliveryLab::enabled(),
                'sandbox' => DeliveryLab::sandbox(),
                'mode' => DeliveryLab::mode(),
                'allowlisted_recipients' => count(DeliveryLab::recipients()),
            ],
            'channels' => DeliveryLab::channelReadiness(),
            'counts' => [
                'queued' => (int) ($statusCounts['queued'] ?? 0),
                'sent' => (int) ($statusCounts['sent'] ?? 0),
                'delivered' => (int) ($statusCounts['delivered'] ?? 0),
                'failed' => (int) ($statusCounts['failed'] ?? 0),
                'simulated' => (clone $base)->where('meta->sandbox', true)->count(),
                'blocked' => (clone $base)->where('meta->lab->blocked', true)->count(),
            ],
            'recent' => $recent,
        ]);
    }

    public function preview(Request $request, Event $event, Guest $guest): JsonResponse
    {
        $this->authorize('view', $event);

        if ($guest->event_id !== $event->id) {
            abort(404);
        }

        $this->authorize('view', $guest);

        $data = $request->validate([
            'kind' => ['required', Rule::in(self::KINDS)],
            'channel' => ['required', Rule::in(self::CHANNELS)],
        ]);

        $guest->setRelation('event', $event);

        return response()->json([
            'kind' => $data['kind'],
            'channel' => $data['channel'],
            'sandbox' => DeliveryLab::sandbox(),
            'content' => $this->previewContent($guest, $data['kind'], $data['channel']),
        ]);
    }

    public function testSend(Request $request, Event $event, Guest $guest): JsonResponse
    {
        $this->authorize('view', $event);

        if ($guest->event_id !== $event->id) {
            abort(404);
        }

        $this->authorize('view', $guest);

        if (! DeliveryLab::enabled()) {
            throw ValidationException::withMessages([
                'channel' => ['Enable the Delivery Lab to send test messages.'],
            ]);
        }

        $data = $request->validate([
            'kind' => ['required', Rule::in(self::KINDS)],
            'channel' => ['required', Rule::in(self::CHANNELS)],
        ]);

        $channel = $data['channel'];
        $kind = $data['kind'];
        $recipient = $this->recipient($guest, $channel);

        if ($recipient === null) {
            throw ValidationException::withMessages([
                'channel' => ['The guest has no destination for this channel.'],
            ]);
        }

        // Debounce: repeated clicks cannot trigger duplicate test sends.
        $seconds = DeliveryLab::testSendDebounceSeconds();
        $lockKey = sprintf('delivery-lab:test:%d:%s:%s', $guest->id, $kind, $channel);
        if ($seconds > 0 && ! Cache::add($lockKey, true, now()->addSeconds($seconds))) {
            return response()->json([
                'message' => 'A test send was just requested. Please wait a moment before trying again.',
            ], 429);
        }

        $guest->setRelation('event', $event);

        // Blocked (not allow-listed): record an observable lab result, never send.
        if (! DeliveryLab::isAllowed($recipient)) {
            $delivery = $this->startLabDelivery($guest, $kind, $channel, [
                'blocked' => true,
                'outcome' => 'blocked',
            ]);
            $delivery->recordFailure('blocked', 'Blocked by the Delivery Lab (recipient is not on the test allowlist).');

            return response()->json([
                'outcome' => 'blocked',
                'delivery' => $this->deliveryPayload($delivery->fresh(['guest.event'])),
            ], 422);
        }

        $notification = $this->notification($guest, $kind);
        $notification->onlyChannels([$channel]);

        $delivery = $this->startLabDelivery($guest, $kind, $channel);
        $notification->setDeliveryId($channel, $delivery->id);

        try {
            if ($channel === 'mail') {
                // Synchronous send so the lab reports the real transport outcome.
                MailDelivery::send($guest, $notification, $delivery);
            } else {
                $guest->notify($notification);
            }
        } catch (Throwable $e) {
            $delivery->refresh();
            if ($delivery->status === 'queued') {
                $delivery->forceFill(['status' => 'failed', 'failed_at' => now(), 'error' => 'The message could not be sent.'])->save();
            }
        }

        $delivery->refresh();

        $channelMode = DeliveryLab::channelReadiness()[$channel]['mode'] ?? 'provider';
        $outcome = $delivery->status === 'sent'
            ? ($channelMode === 'sandbox' ? 'captured' : 'accepted')
            : 'failed';

        $meta = (array) ($delivery->meta ?? []);
        $meta['lab'] = array_merge((array) ($meta['lab'] ?? []), ['outcome' => $outcome]);
        $delivery->forceFill(['meta' => $meta])->save();

        return response()->json([
            'outcome' => $outcome,
            'delivery' => $this->deliveryPayload($delivery->fresh(['guest.event'])),
        ]);
    }

    private function ownerId(Request $request): int
    {
        $user = $request->user();

        return (int) ($user->isStaff() ? $user->owner_id : $user->id);
    }

    private function recipient(Guest $guest, string $channel): ?string
    {
        $value = $channel === 'mail' ? $guest->email : $guest->phone;
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    private function notification(Guest $guest, string $kind)
    {
        return $kind === 'reminder'
            ? new ReminderNotification($guest->event, $guest->invite_url_absolute)
            : new InvitationNotification($guest->event, $guest->invite_url_absolute);
    }

    /**
     * @param  array<string, mixed>  $lab
     */
    private function startLabDelivery(Guest $guest, string $kind, string $channel, array $lab = []): InvitationDelivery
    {
        return InvitationDelivery::create([
            'guest_id' => $guest->id,
            'kind' => $kind,
            'channel' => $channel,
            'status' => 'queued',
            'meta' => [
                'sandbox' => DeliveryLab::sandbox(),
                'lab' => array_merge([
                    'test' => true,
                    'sandbox' => DeliveryLab::sandbox(),
                    'channel' => $channel,
                    'kind' => $kind,
                ], $lab),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function previewContent(Guest $guest, string $kind, string $channel): array
    {
        $notification = $this->notification($guest, $kind);

        // Pass the guest so the previewed SMS/WhatsApp text is composed exactly
        // as it is for delivery, including the personalized greeting.
        $preview = $notification->preview($guest);

        if ($channel === 'mail') {
            return [
                'subject' => (string) ($preview['mail']['subject'] ?? ''),
                'html' => (string) ($preview['mail']['html'] ?? ''),
                'text' => (string) ($preview['mail']['text'] ?? ''),
                'action_url' => $preview['mail']['action_url'] ?? null,
            ];
        }

        if ($channel === 'sms') {
            $message = (string) ($preview['sms']['message'] ?? '');

            return [
                'message' => $message,
                'link' => $guest->invite_url_absolute,
                'character_count' => mb_strlen($message),
            ];
        }

        return [
            'message' => (string) ($preview['whatsapp']['message'] ?? ''),
            'link' => $guest->invite_url_absolute,
            'template_indication' => 'Free-form text message (no approved template configured).',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function deliveryPayload(InvitationDelivery $delivery): array
    {
        $meta = (array) ($delivery->meta ?? []);
        $lab = (array) ($meta['lab'] ?? []);
        $provider = (array) ($meta['provider'] ?? []);
        $captured = (array) ($meta['captured'] ?? []);
        $sandbox = ($meta['sandbox'] ?? false) === true;

        return [
            'id' => $delivery->id,
            'kind' => $delivery->kind,
            'channel' => $delivery->channel,
            'status' => $delivery->status,
            'mode' => $sandbox ? 'sandbox' : 'provider',
            'simulated' => $sandbox,
            'blocked' => ($lab['blocked'] ?? false) === true,
            'outcome' => $lab['outcome'] ?? null,
            'recipient' => $this->maskedRecipient($delivery->guest, $delivery->channel),
            'provider_reference' => $delivery->provider_message_id,
            'provider_status' => $provider['reason'] ?? null,
            'error' => $delivery->error,
            'captured_message' => $captured['message'] ?? null,
            'sent_at' => $delivery->sent_at?->toIso8601String(),
            'delivered_at' => $delivery->delivered_at?->toIso8601String(),
            'failed_at' => $delivery->failed_at?->toIso8601String(),
            'created_at' => $delivery->created_at?->toIso8601String(),
            'guest' => $delivery->guest ? ['id' => $delivery->guest->id, 'name' => $delivery->guest->name] : null,
            'event' => $delivery->guest?->event
                ? ['id' => $delivery->guest->event->id, 'title' => $delivery->guest->event->title]
                : null,
        ];
    }

    private function maskedRecipient(?Guest $guest, string $channel): ?string
    {
        if (! $guest) {
            return null;
        }

        if ($channel === 'mail') {
            $email = (string) $guest->email;

            if ($email === '') {
                return null;
            }

            [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');
            $head = mb_substr($name, 0, 1);

            return $head.str_repeat('•', max(0, mb_strlen($name) - 1)).($domain !== '' ? '@'.$domain : '');
        }

        $phone = (string) $guest->phone;

        if ($phone === '') {
            return null;
        }

        $length = strlen($phone);

        if ($length <= 4) {
            return str_repeat('•', $length);
        }

        return substr($phone, 0, 3).str_repeat('•', max(0, $length - 6)).substr($phone, -3);
    }
}
