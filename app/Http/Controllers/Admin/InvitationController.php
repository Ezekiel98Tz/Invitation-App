<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendInvitationJob;
use App\Models\Event;
use App\Models\Guest;
use App\Models\InvitationDelivery;
use App\Notifications\InvitationNotification;
use App\Notifications\ReminderNotification;
use App\Services\GuestCommunication;
use App\Support\AuditLogger;
use App\Support\PageSize;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InvitationController extends Controller
{
    public function __construct(private readonly GuestCommunication $communication)
    {
    }

    public function channels(Request $request, Event $event): JsonResponse
    {
        $this->authorize('view', $event);

        return response()->json($this->communication->channelOptions());
    }

    public function preview(Request $request, Event $event): JsonResponse
    {
        $this->authorize('view', $event);

        $data = $request->validate([
            'kind' => ['nullable', Rule::in(GuestCommunication::KINDS)],
            'guest_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $kind = $data['kind'] ?? 'invitation';

        if (isset($data['guest_id'])) {
            $guest = $event->guests()->findOrFail($data['guest_id']);
            $this->authorize('view', $guest);
        } else {
            $guest = $event->guests()->orderBy('id')->first();
        }

        if (! $guest) {
            $guest = new Guest([
                'name' => 'Preview Guest',
                'email' => 'preview@example.test',
                'phone' => '+255000000000',
                'status' => 'pending',
            ]);
            $guest->invite_token = Str::random(32);
        }

        $guest->setRelation('event', $event);

        $notification = $kind === 'reminder'
            ? new ReminderNotification($event, $guest->invite_url_absolute)
            : new InvitationNotification($event, $guest->invite_url_absolute);

        return response()->json([
            'event_id' => $event->id,
            'kind' => $kind,
            'recipient' => [
                'id' => $guest->exists ? $guest->id : null,
                'name' => $guest->name,
            ],
            'channels' => $notification->preview($guest),
            'available_channels' => $this->communication->channelOptions(),
        ]);
    }

    public function send(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);

        $data = $request->validate([
            'channel' => ['nullable', Rule::in(GuestCommunication::CHANNELS)],
            'guest_ids' => ['nullable', 'array', 'max:500'],
            'guest_ids.*' => ['integer', 'distinct', 'min:1'],
        ]);

        $channel = $data['channel'] ?? null;
        $selectedIds = $data['guest_ids'] ?? null;

        if ($selectedIds !== null && $channel === null) {
            throw ValidationException::withMessages([
                'channel' => ['Select a communication channel for the selected guests.'],
            ]);
        }

        if ($channel === null) {
            // Original bulk operation: every unsent guest, configured channels.
            $count = 0;

            $event->guests()
                ->unsent()
                ->orderBy('id')
                ->chunk(500, function ($guests) use (&$count) {
                    foreach ($guests as $guest) {
                        dispatch(new SendInvitationJob($guest));
                        $count++;
                    }
                });

            AuditLogger::log($request, 'invitations.send', $event, ['queued' => $count]);

            return response()->json(['queued' => $count], 202);
        }

        $rejected = [];
        $queued = 0;

        if ($selectedIds !== null) {
            $found = $event->guests()->whereIn('id', $selectedIds)->get()->keyBy('id');

            foreach ($selectedIds as $guestId) {
                $guest = $found->get($guestId);

                if (! $guest) {
                    $rejected[] = [
                        'guest_id' => (int) $guestId,
                        'name' => null,
                        'reason' => 'This guest is not part of this event.',
                    ];

                    continue;
                }

                $result = $this->communication->queue($guest, 'invitation', $channel, individual: false);

                if ($result['queued']) {
                    $queued++;
                } else {
                    $rejected[] = [
                        'guest_id' => $guest->id,
                        'name' => $guest->name,
                        'reason' => $result['reason'],
                    ];
                }
            }
        } else {
            $event->guests()
                ->unsent()
                ->orderBy('id')
                ->chunkById(500, function ($guests) use (&$queued, &$rejected, $channel) {
                    foreach ($guests as $guest) {
                        $result = $this->communication->queue($guest, 'invitation', $channel, individual: false);

                        if ($result['queued']) {
                            $queued++;
                        } else {
                            $rejected[] = [
                                'guest_id' => $guest->id,
                                'name' => $guest->name,
                                'reason' => $result['reason'],
                            ];
                        }
                    }
                });
        }

        AuditLogger::log($request, 'invitations.send', $event, [
            'channel' => $channel,
            'queued' => $queued,
            'rejected' => count($rejected),
        ]);

        return response()->json([
            'queued' => $queued,
            'kind' => 'invitation',
            'channel' => $channel,
            'rejected' => $rejected,
        ], 202);
    }

    public function deliveries(Request $request, Event $event): JsonResponse
    {
        $this->authorize('view', $event);

        // The endpoint groups attempts by guest. Ordering guest groups by their
        // latest delivery id (with the guest id as a deterministic tie-breaker)
        // gives a newest-first history without pretending these groups are one
        // globally ordered, flat activity feed.
        $deliveries = $event->guests()
            ->with(['deliveries' => fn ($q) => $q->orderByDesc('created_at')->orderByDesc('id')])
            ->withMax('deliveries as latest_delivery_id', 'id')
            ->orderByDesc('latest_delivery_id')
            ->orderByDesc('id')
            ->paginate(PageSize::resolve($request));

        $deliveries->through(function (Guest $guest) {
            $guest->makeHidden('latest_delivery_id');

            return $guest;
        });

        return response()->json($deliveries);
    }

    public function retry(Request $request, Event $event, InvitationDelivery $delivery): JsonResponse
    {
        $this->authorize('update', $event);

        $delivery->loadMissing('guest');

        if (! $delivery->guest || $delivery->guest->event_id !== $event->id) {
            abort(404);
        }

        $result = $this->communication->retry($delivery);

        if (! $result['queued']) {
            return response()->json(['message' => $result['reason']], 422);
        }

        AuditLogger::log($request, 'deliveries.retry', $delivery, [
            'event_id' => $event->id,
            'retry_delivery_id' => $result['delivery']?->id,
        ]);

        return response()->json([
            'queued' => true,
            'queued_count' => 1,
            'delivery_id' => $result['delivery']?->id,
        ], 202);
    }

    public function failed(Request $request, Event $event): JsonResponse
    {
        $this->authorize('view', $event);

        $failed = InvitationDelivery::query()
            ->with('guest')
            ->where('status', 'failed')
            ->whereHas('guest', fn ($q) => $q->where('event_id', $event->id))
            ->orderByDesc('id')
            ->paginate(PageSize::resolve($request));

        return response()->json($failed);
    }

    public function retryFailed(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);

        $deliveries = InvitationDelivery::query()
            ->with('guest')
            ->where('status', 'failed')
            ->whereHas('guest', fn ($q) => $q->where('event_id', $event->id))
            ->orderBy('id')
            ->limit(500)
            ->get();

        $queued = 0;
        $rejected = 0;

        foreach ($deliveries as $delivery) {
            $result = $this->communication->retry($delivery);

            if ($result['queued']) {
                $queued++;
            } else {
                $rejected++;
            }
        }

        AuditLogger::log($request, 'deliveries.retry_failed', $event, [
            'queued' => $queued,
            'rejected' => $rejected,
        ]);

        return response()->json([
            'queued' => $queued,
            'rejected' => $rejected,
        ], 202);
    }
}
