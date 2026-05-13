<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendDeliveryJob;
use App\Jobs\SendInvitationJob;
use App\Models\Event;
use App\Models\Guest;
use App\Models\InvitationDelivery;
use App\Notifications\InvitationNotification;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class InvitationController extends Controller
{
    public function preview(Request $request, Event $event): JsonResponse
    {
        $this->authorize('view', $event);

        $guest = $event->guests()->orderBy('id')->first();

        if (! $guest) {
            $guest = new Guest([
                'name' => 'Preview Guest',
                'email' => 'preview@example.test',
                'phone' => '+255000000000',
                'status' => 'pending',
            ]);
            $guest->invite_token = Str::random(32);
            $guest->setRelation('event', $event);
        } else {
            $guest->setRelation('event', $event);
        }

        $notification = new InvitationNotification($event, $guest->invite_url);

        return response()->json([
            'event_id' => $event->id,
            'channels' => $notification->preview(),
        ]);
    }

    public function send(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);

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

    public function deliveries(Request $request, Event $event): JsonResponse
    {
        $this->authorize('view', $event);

        $deliveries = $event->guests()
            ->with(['deliveries' => fn ($q) => $q->orderByDesc('id')])
            ->orderBy('id')
            ->paginate(20);

        return response()->json($deliveries);
    }

    public function retry(Request $request, Event $event, InvitationDelivery $delivery): JsonResponse
    {
        $this->authorize('update', $event);

        $delivery->loadMissing('guest');

        if (! $delivery->guest || $delivery->guest->event_id !== $event->id) {
            abort(404);
        }

        dispatch(new SendDeliveryJob($delivery->guest, $delivery->kind, $delivery->channel, $delivery->id));

        AuditLogger::log($request, 'deliveries.retry', $delivery, ['event_id' => $event->id]);

        return response()->json(['queued' => true], 202);
    }

    public function failed(Request $request, Event $event): JsonResponse
    {
        $this->authorize('view', $event);

        $failed = InvitationDelivery::query()
            ->with('guest')
            ->where('status', 'failed')
            ->whereHas('guest', fn ($q) => $q->where('event_id', $event->id))
            ->orderByDesc('id')
            ->paginate(50);

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

        foreach ($deliveries as $delivery) {
            if (! $delivery->guest) {
                continue;
            }

            dispatch(new SendDeliveryJob($delivery->guest, $delivery->kind, $delivery->channel, $delivery->id));
        }

        AuditLogger::log($request, 'deliveries.retry_failed', $event, ['queued' => $deliveries->count()]);

        return response()->json(['queued' => $deliveries->count()], 202);
    }
}
