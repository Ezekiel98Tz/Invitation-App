<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Celebration;
use App\Models\Event;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Account-scoped celebrations: a grouping container for related occasions.
 *
 * A celebration never owns event data. Attaching or detaching an event only
 * changes `events.celebration_id`; dates, venues, settings, invitation designs,
 * guests, RSVPs and deliveries stay with the event. Deleting a celebration
 * detaches its events and never deletes them.
 */
class CelebrationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Celebration::class);

        $user = $request->user();
        $ownerId = $user->isStaff() ? $user->owner_id : $user->id;

        $celebrations = Celebration::query()
            ->where('user_id', $ownerId)
            ->withCount('events')
            ->orderByDesc('id')
            ->paginate(15);

        return response()->json($celebrations);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Celebration::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $celebration = new Celebration();
        $celebration->user_id = $request->user()->id;
        $celebration->name = $data['name'];
        $celebration->description = $data['description'] ?? null;
        $celebration->save();

        AuditLogger::log($request, 'celebration.create', $celebration);

        return response()->json($celebration, 201);
    }

    public function show(Request $request, Celebration $celebration): JsonResponse
    {
        $this->authorize('view', $celebration);

        $events = $celebration->events()
            ->orderBy('event_start')
            ->get();

        return response()->json([
            'celebration' => $celebration->only(['id', 'name', 'description', 'created_at', 'updated_at']),
            'events' => $events,
        ]);
    }

    public function update(Request $request, Celebration $celebration): JsonResponse
    {
        $this->authorize('update', $celebration);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $celebration->fill($data)->save();

        AuditLogger::log($request, 'celebration.update', $celebration);

        return response()->json($celebration);
    }

    public function destroy(Request $request, Celebration $celebration): JsonResponse
    {
        $this->authorize('delete', $celebration);

        // Detach first so events survive as standalone occasions. The foreign
        // key is also nullOnDelete, so this is safe even if the explicit detach
        // is ever bypassed.
        $detached = $celebration->events()->count();
        $celebration->events()->update(['celebration_id' => null]);

        $celebration->delete();

        AuditLogger::log($request, 'celebration.delete', $celebration, ['detached_events' => $detached]);

        return response()->json(['deleted' => true, 'detached_events' => $detached]);
    }

    public function attachEvent(Request $request, Celebration $celebration, Event $event): JsonResponse
    {
        $this->authorize('update', $celebration);
        $this->authorize('update', $event);

        $this->ensureSameAccount($celebration, $event);

        $event->celebration_id = $celebration->id;
        $event->save();

        AuditLogger::log($request, 'celebration.event.attach', $celebration, ['event_id' => $event->id]);

        return response()->json($event->fresh());
    }

    public function detachEvent(Request $request, Celebration $celebration, Event $event): JsonResponse
    {
        $this->authorize('update', $celebration);
        $this->authorize('update', $event);

        $this->ensureSameAccount($celebration, $event);

        if ($event->celebration_id !== $celebration->id) {
            abort(404);
        }

        $event->celebration_id = null;
        $event->save();

        AuditLogger::log($request, 'celebration.event.detach', $celebration, ['event_id' => $event->id]);

        return response()->json($event->fresh());
    }

    /**
     * A celebration and its events must belong to the same account. This is
     * enforced beyond the per-resource policies because a staff member may be
     * authorized on two owners, and a celebration must never adopt an event
     * from a different owner.
     */
    private function ensureSameAccount(Celebration $celebration, Event $event): void
    {
        if ($event->user_id !== $celebration->user_id) {
            abort(403, 'An event can only belong to a celebration within the same account.');
        }
    }
}
