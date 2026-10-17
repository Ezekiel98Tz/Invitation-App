<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\Guest;
use App\Models\InvitationCredential;
use App\Services\CredentialIssuer;
use App\Support\AuditLogger;
use App\Support\CredentialAccess;
use App\Support\PageSize;
use App\Support\PassQrReference;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Event-day check-in.
 *
 * Attendance is recorded only by an explicit, authorized check-in action.
 * Lookup/scan is read-only and never writes attendance. Duplicate active
 * check-ins are prevented by a transaction + unique index; reversal preserves
 * the original row for audit.
 */
class CheckInController extends Controller
{
    private const METHODS = ['qr', 'code', 'manual', 'override'];

    public function __construct(private CredentialIssuer $issuer) {}

    public function lookup(Request $request, Event $event): JsonResponse
    {
        $this->authorize('view', $event);

        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:32'],
            'qr_token' => ['nullable', 'string', 'max:2048'],
            'guest_id' => ['nullable', 'integer'],
        ]);

        $guest = null;
        $credential = null;

        if (! empty($data['qr_token'])) {
            $credential = PassQrReference::resolve($data['qr_token']);

            if ($credential === null || $credential->event_id !== $event->id) {
                return response()->json($this->decision($event, null, null));
            }

            $credential->setRelation('event', $event);
            $guest = $credential->guest;
        } elseif (! empty($data['code'])) {
            $credential = $this->issuer->resolve($data['code']);
            $guest = $credential?->guest;
        } elseif (! empty($data['guest_id'])) {
            $guest = $event->guests()->with('rsvp')->find($data['guest_id']);
            $credential = $guest ? $this->issuer->current($guest) : null;
        } else {
            throw ValidationException::withMessages(['code' => ['Provide a code or a guest.']]);
        }

        return response()->json($this->decision($event, $guest, $credential));
    }

    public function store(Request $request, Event $event, Guest $guest): JsonResponse
    {
        $this->authorize('view', $event);

        if ($guest->event_id !== $event->id) {
            abort(404);
        }

        $this->authorize('view', $guest);

        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:32'],
            'qr_token' => ['nullable', 'string', 'max:2048'],
            'method' => ['nullable', Rule::in(self::METHODS)],
            'party_size' => ['nullable', 'integer', 'min:1', 'max:20'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $method = $data['method']
            ?? (! empty($data['qr_token']) ? Attendance::METHOD_QR : Attendance::METHOD_MANUAL);

        $attendance = DB::transaction(function () use ($request, $event, $guest, $data, $method) {
            // Serialise concurrent check-ins for this guest.
            $locked = Guest::whereKey($guest->id)->lockForUpdate()->first();

            if (! $locked) {
                abort(404);
            }

            if (Attendance::where('guest_id', $guest->id)->where('status', Attendance::STATUS_VALID)->exists()) {
                throw ValidationException::withMessages(['guest' => ['This guest is already checked in.']]);
            }

            $credential = $this->issuer->current($guest);

            if ($method === Attendance::METHOD_OVERRIDE) {
                if (empty($data['note'])) {
                    throw ValidationException::withMessages(['note' => ['An override requires a reason.']]);
                }
            } else {
                if (! CredentialAccess::isUsable($credential)) {
                    throw ValidationException::withMessages(['credential' => ['The credential is not valid for entry.']]);
                }

                if (! empty($data['code'])) {
                    $resolved = $this->issuer->resolve($data['code']);

                    if (
                        $resolved === null
                        || $resolved->guest_id !== $guest->id
                        || $resolved->id !== $credential?->id
                    ) {
                        throw ValidationException::withMessages(['code' => ['The code is not the current credential for this guest.']]);
                    }
                }

                if (! empty($data['qr_token'])) {
                    $resolved = PassQrReference::resolve($data['qr_token']);

                    if (
                        $resolved === null
                        || $resolved->event_id !== $event->id
                        || $resolved->guest_id !== $guest->id
                        || $resolved->id !== $credential?->id
                    ) {
                        throw ValidationException::withMessages(['qr_token' => ['The pass is no longer the current credential for this guest.']]);
                    }
                }
            }

            try {
                $attendance = Attendance::create([
                    'event_id' => $event->id,
                    'guest_id' => $guest->id,
                    'credential_id' => $credential?->id,
                    'rsvp_id' => $guest->rsvp?->id,
                    'checked_in_at' => now(),
                    'checked_in_by' => $request->user()->id,
                    'method' => $method,
                    'party_size' => $data['party_size'] ?? ($guest->rsvp?->attending_count ?? 1),
                    'status' => Attendance::STATUS_VALID,
                    'note' => $data['note'] ?? null,
                    'active_key' => $guest->id,
                ]);
            } catch (QueryException $e) {
                // The unique active-key index lost a race: treat as duplicate.
                throw ValidationException::withMessages(['guest' => ['This guest is already checked in.']]);
            }

            AuditLogger::log($request, 'attendance.check_in', $attendance, [
                'event_id' => $event->id,
                'guest_id' => $guest->id,
                'method' => $method,
            ]);

            return $attendance;
        });

        return response()->json([
            'attendance' => $this->attendancePayload($attendance),
            'guest' => ['id' => $guest->id, 'name' => $guest->name],
        ], 201);
    }

    public function index(Request $request, Event $event): JsonResponse
    {
        $this->authorize('view', $event);

        $query = Attendance::query()
            ->where('event_id', $event->id)
            ->with(['guest:id,name']);

        if ($request->filled('status')
            && in_array($request->string('status')->toString(), [Attendance::STATUS_VALID, Attendance::STATUS_REVERSED], true)) {
            $query->where('status', $request->string('status')->toString());
        }

        // Bounded, navigable pagination instead of a silent hard cap. `id` is a
        // unique tie-breaker, so the order is deterministic across pages.
        $attendances = $query
            ->orderByDesc('id')
            ->paginate(PageSize::resolve($request));

        $attendances->through(fn (Attendance $attendance) => $this->attendancePayload($attendance));

        return response()->json([
            'checked_in' => Attendance::where('event_id', $event->id)->where('status', Attendance::STATUS_VALID)->count(),
            'attendances' => $attendances->items(),
            'current_page' => $attendances->currentPage(),
            'last_page' => $attendances->lastPage(),
            'per_page' => $attendances->perPage(),
            'total' => $attendances->total(),
            'prev_page_url' => $attendances->previousPageUrl(),
            'next_page_url' => $attendances->nextPageUrl(),
        ]);
    }

    public function reverse(Request $request, Event $event, Attendance $attendance): JsonResponse
    {
        $this->authorize('view', $event);

        if ($attendance->event_id !== $event->id) {
            abort(404);
        }

        $data = $request->validate([
            'note' => ['required', 'string', 'max:500'],
        ]);

        if ($attendance->status !== Attendance::STATUS_VALID) {
            throw ValidationException::withMessages(['attendance' => ['This attendance is not active.']]);
        }

        $attendance->forceFill([
            'status' => Attendance::STATUS_REVERSED,
            'reversed_at' => now(),
            'reversed_by' => $request->user()->id,
            'reversal_reason' => $data['note'],
            'active_key' => null,
        ])->save();

        AuditLogger::log($request, 'attendance.reverse', $attendance, [
            'event_id' => $event->id,
            'reason' => $data['note'],
        ]);

        return response()->json(['attendance' => $this->attendancePayload($attendance->fresh(['guest']))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decision(Event $event, ?Guest $guest, ?InvitationCredential $credential): array
    {
        if ($guest === null || $guest->event_id !== $event->id) {
            return ['state' => 'invalid', 'guest' => null, 'credential' => null, 'checked_in' => false];
        }

        $attendance = Attendance::where('guest_id', $guest->id)
            ->where('status', Attendance::STATUS_VALID)
            ->first();

        $credentialStatus = CredentialAccess::status($credential);

        $state = match (true) {
            $attendance !== null => 'already_used',
            $credentialStatus === CredentialAccess::VALID && $guest->status === 'accepted' => 'valid',
            $credentialStatus === CredentialAccess::REVOKED => 'revoked',
            $credentialStatus === CredentialAccess::EXPIRED => 'expired',
            $credentialStatus === CredentialAccess::NOT_YET => 'not_yet',
            $credentialStatus === CredentialAccess::UNAVAILABLE => 'invalid',
            default => 'ineligible',
        };

        return [
            'state' => $state,
            'credential' => $credential === null ? null : [
                'status' => $credential->status,
                'reason' => $credentialStatus,
            ],
            'guest' => [
                'id' => $guest->id,
                'name' => $guest->name,
                'status' => $guest->status,
            ],
            'rsvp' => ['attending_count' => $guest->rsvp?->attending_count],
            'checked_in' => $attendance !== null,
            'attendance' => $attendance === null ? null : [
                'id' => $attendance->id,
                'checked_in_at' => $attendance->checked_in_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attendancePayload(Attendance $attendance): array
    {
        return [
            'id' => $attendance->id,
            'guest_id' => $attendance->guest_id,
            'guest' => $attendance->relationLoaded('guest') && $attendance->guest
                ? ['id' => $attendance->guest->id, 'name' => $attendance->guest->name]
                : null,
            'method' => $attendance->method,
            'party_size' => $attendance->party_size,
            'status' => $attendance->status,
            'checked_in_at' => $attendance->checked_in_at?->toIso8601String(),
            'checked_in_by' => $attendance->checked_in_by,
            'reversed_at' => $attendance->reversed_at?->toIso8601String(),
            'reversed_by' => $attendance->reversed_by,
            'reversal_reason' => $attendance->reversal_reason,
            'note' => $attendance->note,
        ];
    }
}
