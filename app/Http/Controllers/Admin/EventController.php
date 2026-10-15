<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Celebration;
use App\Models\Event;
use App\Support\AuditLogger;
use App\Support\EventCapabilities;
use App\Support\EventTypes;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class EventController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $ownerId = $user->isStaff() ? $user->owner_id : $user->id;

        $query = Event::query()
            ->where('user_id', $ownerId)
            // Eager-load the celebration (id + name) so cards can show which
            // celebration an event belongs to without per-event queries.
            ->with('celebration:id,name');

        // `standalone=1` returns only events not attached to any celebration,
        // used by the "add an existing event" picker. Absent/false leaves the
        // list unchanged for existing callers.
        if ($request->boolean('standalone')) {
            $query->whereNull('celebration_id');
        }

        if ($request->filled('search')) {
            $query->where('title', 'like', '%'.$request->string('search')->toString().'%');
        }

        $events = $query
            ->orderByDesc('event_start')
            ->paginate(15);

        return response()->json($events);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Event::class);

        $data = $request->validate(array_merge([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'event_start' => ['required', 'date', 'after:now'],
            'timezone' => ['nullable', 'string', 'max:255'],
            'venue' => ['required', 'string', 'max:255'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'celebration_id' => ['nullable', 'integer', Rule::exists('celebrations', 'id')],
        ], $this->settingsRules()));

        // A client-supplied celebration id is never trusted on its own: the
        // celebration must exist and the acting user must be authorized to
        // manage it, and it must belong to the same account as the new event.
        $celebration = null;

        if (($data['celebration_id'] ?? null) !== null) {
            $celebration = Celebration::findOrFail($data['celebration_id']);
            $this->authorize('update', $celebration);

            if ($celebration->user_id !== $request->user()->id) {
                abort(403, 'You may only add events to a celebration in your own account.');
            }
        }

        $settings = $this->mergeSettings(
            [
                'allow_plus_ones' => false,
                'custom_questions' => [],
                'enable_waitlist' => false,
            ],
            (array) Arr::get($data, 'settings', [])
        );

        $event = new Event();
        $event->user_id = $request->user()->id;
        $event->title = $data['title'];
        $event->description = $data['description'] ?? null;
        $event->event_start = Carbon::parse($data['event_start']);
        $event->timezone = $data['timezone'] ?? 'Africa/Dar_es_Salaam';
        $event->venue = $data['venue'];
        $event->capacity = $data['capacity'] ?? null;
        $event->settings = $settings;

        if ($celebration !== null) {
            $event->celebration_id = $celebration->id;
        }

        $event->save();

        AuditLogger::log($request, 'event.create', $event);

        return response()->json($event, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Event $event): JsonResponse
    {
        $this->authorize('view', $event);

        $event->load('celebration:id,name');

        return response()->json($event);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);

        if ($event->event_start->isPast()) {
            return response()->json(['message' => 'Past events cannot be updated.'], 422);
        }

        $data = $request->validate(array_merge([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'event_start' => ['sometimes', 'required', 'date', 'after:now'],
            'timezone' => ['nullable', 'string', 'max:255'],
            'venue' => ['sometimes', 'required', 'string', 'max:255'],
            'capacity' => ['nullable', 'integer', 'min:1'],
        ], $this->settingsRules()));

        $event->fill(Arr::except($data, ['settings']));

        if (array_key_exists('event_start', $data)) {
            $event->event_start = Carbon::parse($data['event_start']);
        }

        if (array_key_exists('settings', $data)) {
            // Merge submitted settings over the existing ones so unrelated keys
            // (for example the saved invitation `design`) are preserved. Omitted
            // keys are left untouched; keys sent explicitly override the stored
            // value. The event type and capability map are then re-resolved and
            // persisted in canonical form without discarding unknown keys.
            $event->settings = $this->mergeSettings(
                (array) ($event->settings ?? []),
                (array) $data['settings']
            );
        }

        $event->save();

        AuditLogger::log($request, 'event.update', $event);

        return response()->json($event);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Event $event): JsonResponse
    {
        $this->authorize('delete', $event);

        if ($event->guests()->pending()->exists()) {
            return response()->json(['message' => 'Cannot delete an event with pending invitations.'], 422);
        }

        $event->delete();

        AuditLogger::log($request, 'event.delete', $event);

        return response()->json(['deleted' => true]);
    }

    public function duplicate(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);

        $copy = $event->replicate();
        $copy->title = $event->title.' (Copy)';
        $copy->push();

        AuditLogger::log($request, 'event.duplicate', $event, ['copy_id' => $copy->id]);

        return response()->json($copy, 201);
    }

    /**
     * Validation rules shared by store/update for the `settings` payload,
     * including the configuration-driven event type and capability map.
     *
     * @return array<string, mixed>
     */
    private function settingsRules(): array
    {
        return [
            'settings' => ['nullable', 'array'],
            'settings.allow_plus_ones' => ['nullable', 'boolean'],
            'settings.custom_questions' => ['nullable', 'array'],
            'settings.custom_questions.*.key' => ['required_with:settings.custom_questions', 'string', 'max:100'],
            'settings.custom_questions.*.type' => ['nullable', Rule::in(['text', 'number', 'boolean', 'choice'])],
            'settings.custom_questions.*.required' => ['nullable', 'boolean'],
            'settings.custom_questions.*.options' => ['nullable', 'array'],
            'settings.enable_waitlist' => ['nullable', 'boolean'],
            'settings.event_type' => ['nullable', 'string', Rule::in(EventTypes::keys())],
            'settings.capabilities' => ['nullable', 'array', $this->capabilitiesRule()],
            'settings.capabilities.*' => ['boolean'],
        ];
    }

    /**
     * Reject unknown capability keys and attempts to enable a capability whose
     * workflow does not exist yet. This keeps a configuration flag from being
     * presented as a working feature.
     */
    private function capabilitiesRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value)) {
                return; // handled by the `array` rule
            }

            foreach ($value as $key => $enabled) {
                if (! is_string($key) || ! EventCapabilities::isKnown($key)) {
                    $fail('The selected capability is not recognised.');
                    continue;
                }

                if ((bool) $enabled && ! EventCapabilities::isImplemented($key)) {
                    $fail("The {$key} capability is not available yet.");
                }
            }
        };
    }

    /**
     * Merge incoming settings over existing ones without discarding unknown
     * keys, then persist the canonical event type and capability map.
     *
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergeSettings(array $existing, array $incoming): array
    {
        $settings = array_merge($existing, $incoming);

        $incomingCapabilities = is_array($incoming['capabilities'] ?? null) ? $incoming['capabilities'] : [];

        // Deep-merge capabilities so a partial capability update preserves the
        // capabilities that were already stored.
        $capabilities = array_merge(
            (array) ($existing['capabilities'] ?? []),
            $incomingCapabilities
        );

        // Legacy compatibility: a legacy value maps onto its capability when the
        // caller did not set the capability explicitly, so existing clients that
        // still send `settings.allow_plus_ones` keep working after the explicit
        // capability structure is introduced. A non-empty `custom_questions`
        // array implies the capability; an empty array is not treated as an
        // authoritative "off" signal (the explicit capability is).
        if (array_key_exists('allow_plus_ones', $incoming) && ! array_key_exists('plus_ones', $incomingCapabilities)) {
            $capabilities['plus_ones'] = (bool) $incoming['allow_plus_ones'];
        }

        if (! empty($incoming['custom_questions']) && ! array_key_exists('custom_questions', $incomingCapabilities)) {
            $capabilities['custom_questions'] = true;
        }

        $settings['capabilities'] = $capabilities;

        $storedType = $incoming['event_type'] ?? ($existing['event_type'] ?? null);
        $settings['event_type'] = EventTypes::resolve(is_string($storedType) ? $storedType : null);

        $settings['capabilities'] = EventCapabilities::resolve(
            $settings,
            EventTypes::defaultCapabilities($settings['event_type'])
        );

        return $settings;
    }
}
