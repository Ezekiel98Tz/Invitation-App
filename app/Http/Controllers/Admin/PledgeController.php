<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Celebration;
use App\Models\Event;
use App\Models\Pledge;
use App\Models\PledgeFulfillment;
use App\Services\PledgeLedger;
use App\Support\PageSize;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Event-scoped pledge information management.
 *
 * These endpoints record what clients report they will give and what they
 * report they have given. Nothing here collects, holds, transfers, verifies or
 * settles money, and nothing here touches invitations, RSVPs, credentials or
 * check-in, so an outstanding pledge can never block a guest workflow.
 */
class PledgeController extends Controller
{
    public function __construct(private readonly PledgeLedger $ledger) {}

    public function index(Request $request, Event $event): JsonResponse
    {
        $this->authorize('view', $event);

        $query = Pledge::query()
            ->where('event_id', $event->id)
            // The list only needs summary balances. Aggregate the fulfillment
            // ledger in SQL instead of loading every fulfillment row per pledge,
            // so a pledge with a long append-only history does not bloat the list.
            ->withCount('fulfillments')
            ->withSum(
                ['fulfillments as recorded_minor' => fn ($q) => $q
                    ->where('status', PledgeFulfillment::STATUS_RECORDED)
                    ->whereNull('reverses_fulfillment_id')],
                'amount_minor',
            )
            ->withSum(
                ['fulfillments as reversed_minor' => fn ($q) => $q->whereNotNull('reverses_fulfillment_id')],
                'amount_minor',
            );

        if ($request->filled('status') && in_array($request->string('status')->toString(), [Pledge::STATUS_ACTIVE, Pledge::STATUS_CANCELLED], true)) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('method')) {
            $method = $request->string('method')->toString();
            $query->whereHas('fulfillments', function ($q) use ($method) {
                $q->where('status', PledgeFulfillment::STATUS_RECORDED)
                    ->whereNull('reverses_fulfillment_id')
                    ->where('method', $method);
            });
        }

        if ($request->filled('guest_id')) {
            $query->where('guest_id', (int) $request->input('guest_id'));
        }

        if ($request->filled('contact_id')) {
            $query->where('contact_id', (int) $request->input('contact_id'));
        }

        if ($request->filled('search')) {
            $like = '%'.$request->string('search')->toString().'%';
            $query->where(function ($q) use ($like) {
                $q->where('pledger_name', 'like', $like)
                    ->orWhere('pledger_email', 'like', $like)
                    ->orWhere('pledger_phone', 'like', $like);
            });
        }

        $pledges = $query->orderByDesc('id')->paginate(PageSize::resolve($request));
        $pledges->through(fn (Pledge $pledge) => $this->pledgePayload($pledge));

        return response()->json($pledges);
    }

    public function store(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);

        $data = $request->validate([
            'guest_id' => ['nullable', 'integer'],
            'contact_id' => ['nullable', 'integer'],
            'pledger_name' => ['nullable', 'string', 'max:255'],
            'pledger_email' => ['nullable', 'email', 'max:255'],
            'pledger_phone' => ['nullable', 'string', 'max:50'],
            'currency' => ['required', 'string', 'size:3'],
            'pledged_amount_minor' => ['required', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
            'source' => ['nullable', Rule::in([Pledge::SOURCE_MANUAL, Pledge::SOURCE_IMPORT])],
        ]);

        $pledge = $this->ledger->create($request, $event, $data);

        return response()->json($this->pledgePayload($pledge->load('fulfillments')), 201);
    }

    public function show(Request $request, Event $event, Pledge $pledge): JsonResponse
    {
        $this->authorize('view', $event);
        $this->ensurePledgeBelongsToEvent($event, $pledge);
        $this->authorize('view', $pledge);

        return response()->json($this->pledgePayload($pledge->load('fulfillments')));
    }

    public function update(Request $request, Event $event, Pledge $pledge): JsonResponse
    {
        $this->authorize('update', $event);
        $this->ensurePledgeBelongsToEvent($event, $pledge);
        $this->authorize('update', $pledge);

        $data = $request->validate([
            'guest_id' => ['nullable', 'integer'],
            'contact_id' => ['nullable', 'integer'],
            'pledger_name' => ['sometimes', 'required', 'string', 'max:255'],
            'pledger_email' => ['nullable', 'email', 'max:255'],
            'pledger_phone' => ['nullable', 'string', 'max:50'],
            'currency' => ['sometimes', 'required', 'string', 'size:3'],
            'pledged_amount_minor' => ['sometimes', 'required', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $pledge = $this->ledger->update($request, $pledge, $data);

        return response()->json($this->pledgePayload($pledge->load('fulfillments')));
    }

    public function cancel(Request $request, Event $event, Pledge $pledge): JsonResponse
    {
        $this->authorize('update', $event);
        $this->ensurePledgeBelongsToEvent($event, $pledge);
        $this->authorize('update', $pledge);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $pledge = $this->ledger->cancel($request, $pledge, $data['reason'] ?? null);

        return response()->json($this->pledgePayload($pledge->load('fulfillments')));
    }

    public function storeFulfillment(Request $request, Event $event, Pledge $pledge): JsonResponse
    {
        $this->authorize('update', $event);
        $this->ensurePledgeBelongsToEvent($event, $pledge);
        $this->authorize('update', $pledge);

        $data = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:1'],
            'method' => ['nullable', Rule::in(PledgeFulfillment::METHODS)],
            'method_detail' => ['nullable', 'string', 'max:255'],
            'received_on' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $fulfillment = $this->ledger->recordFulfillment($request, $pledge, $data);

        return response()->json([
            'fulfillment' => $this->fulfillmentPayload($fulfillment, $pledge->currency),
            'pledge' => $this->pledgePayload($pledge->fresh(['fulfillments'])),
        ], 201);
    }

    public function reverseFulfillment(Request $request, Event $event, Pledge $pledge, PledgeFulfillment $fulfillment): JsonResponse
    {
        $this->authorize('update', $event);
        $this->ensurePledgeBelongsToEvent($event, $pledge);
        $this->authorize('update', $pledge);

        if ((int) $fulfillment->pledge_id !== (int) $pledge->id) {
            abort(404);
        }

        $data = $request->validate([
            'received_on' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $reversal = $this->ledger->reverseFulfillment($request, $pledge, $fulfillment, $data);

        return response()->json([
            'fulfillment' => $this->fulfillmentPayload($reversal, $pledge->currency),
            'pledge' => $this->pledgePayload($pledge->fresh(['fulfillments'])),
        ]);
    }

    public function summary(Request $request, Event $event): JsonResponse
    {
        $this->authorize('view', $event);

        return response()->json(array_merge([
            'event_id' => $event->id,
            'title' => $event->title,
        ], $this->ledger->eventSummary($event)));
    }

    public function accountReport(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Pledge::class);

        $user = $request->user();
        $ownerId = $user->isStaff() ? $user->owner_id : $user->id;

        return response()->json($this->ledger->accountSummary((int) ($ownerId ?? 0)));
    }

    public function celebrationReport(Request $request, Celebration $celebration): JsonResponse
    {
        $this->authorize('view', $celebration);

        return response()->json(array_merge([
            'celebration_id' => $celebration->id,
            'name' => $celebration->name,
        ], $this->ledger->celebrationSummary($celebration)));
    }

    private function ensurePledgeBelongsToEvent(Event $event, Pledge $pledge): void
    {
        if ((int) $pledge->event_id !== (int) $event->id) {
            abort(404);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function pledgePayload(Pledge $pledge): array
    {
        $fulfilled = $this->fulfilledMinor($pledge);

        return [
            'id' => $pledge->id,
            'event_id' => $pledge->event_id,
            'guest_id' => $pledge->guest_id,
            'contact_id' => $pledge->contact_id,
            'pledger_name' => $pledge->pledger_name,
            'pledger_email' => $pledge->pledger_email,
            'pledger_phone' => $pledge->pledger_phone,
            'currency' => $pledge->currency,
            'pledged_amount_minor' => (int) $pledge->pledged_amount_minor,
            'note' => $pledge->note,
            'source' => $pledge->source,
            'status' => $pledge->status,
            'fulfilled_minor' => $fulfilled,
            'outstanding_minor' => $pledge->outstandingMinorFrom($fulfilled),
            'derived_status' => $pledge->derivedStatusFrom($fulfilled),
            'fulfillments_count' => $pledge->relationLoaded('fulfillments')
                ? $pledge->fulfillments->count()
                : (array_key_exists('fulfillments_count', $pledge->getAttributes())
                    ? (int) $pledge->fulfillments_count
                    : null),
            'fulfillments' => $pledge->relationLoaded('fulfillments')
                ? $pledge->fulfillments
                    ->map(fn (PledgeFulfillment $fulfillment) => $this->fulfillmentPayload($fulfillment, $pledge->currency))
                    ->values()
                    ->all()
                : null,
            'created_by' => $pledge->created_by,
            'created_at' => $pledge->created_at?->toIso8601String(),
            'updated_at' => $pledge->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Net reported fulfillment in minor units for the payload.
     *
     * Prefers the loaded `fulfillments` relation; otherwise uses the SQL
     * aggregates added by the list query (avoiding both an N+1 query and loading
     * every fulfillment row); and finally falls back to the model, which is
     * correct for a single pledge (show/create/update paths load the relation).
     */
    private function fulfilledMinor(Pledge $pledge): int
    {
        if ($pledge->relationLoaded('fulfillments')) {
            return $pledge->fulfilledMinor();
        }

        if (array_key_exists('recorded_minor', $pledge->getAttributes())) {
            return (int) ($pledge->recorded_minor ?? 0) - (int) ($pledge->reversed_minor ?? 0);
        }

        return $pledge->fulfilledMinor();
    }

    /**
     * @return array<string, mixed>
     */
    private function fulfillmentPayload(PledgeFulfillment $fulfillment, ?string $currency): array
    {
        return [
            'id' => $fulfillment->id,
            'pledge_id' => $fulfillment->pledge_id,
            'amount_minor' => (int) $fulfillment->amount_minor,
            'currency' => $currency,
            'method' => $fulfillment->method,
            'method_detail' => $fulfillment->method_detail,
            'received_on' => $fulfillment->received_on?->toDateString(),
            'reference' => $fulfillment->reference,
            'note' => $fulfillment->note,
            'recorded_by' => $fulfillment->recorded_by,
            'status' => $fulfillment->status,
            'reverses_fulfillment_id' => $fulfillment->reverses_fulfillment_id,
            'is_reversal' => $fulfillment->isReversal(),
            'created_at' => $fulfillment->created_at?->toIso8601String(),
            'updated_at' => $fulfillment->updated_at?->toIso8601String(),
        ];
    }
}
