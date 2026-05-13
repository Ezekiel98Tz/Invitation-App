<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Support\AuditLogger;
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

        $events = Event::query()
            ->where('user_id', $ownerId)
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

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'event_start' => ['required', 'date', 'after:now'],
            'timezone' => ['nullable', 'string', 'max:255'],
            'venue' => ['required', 'string', 'max:255'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'settings' => ['nullable', 'array'],
            'settings.allow_plus_ones' => ['nullable', 'boolean'],
            'settings.custom_questions' => ['nullable', 'array'],
            'settings.custom_questions.*.key' => ['required_with:settings.custom_questions', 'string', 'max:100'],
            'settings.custom_questions.*.type' => ['nullable', Rule::in(['text', 'number', 'boolean', 'choice'])],
            'settings.custom_questions.*.required' => ['nullable', 'boolean'],
            'settings.custom_questions.*.options' => ['nullable', 'array'],
            'settings.enable_waitlist' => ['nullable', 'boolean'],
        ]);

        $settings = array_merge(
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
        $event->save();

        AuditLogger::log($request, 'event.create', $event);

        return response()->json($event, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
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

        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'event_start' => ['sometimes', 'required', 'date', 'after:now'],
            'timezone' => ['nullable', 'string', 'max:255'],
            'venue' => ['sometimes', 'required', 'string', 'max:255'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'settings' => ['nullable', 'array'],
            'settings.allow_plus_ones' => ['nullable', 'boolean'],
            'settings.custom_questions' => ['nullable', 'array'],
            'settings.custom_questions.*.key' => ['required_with:settings.custom_questions', 'string', 'max:100'],
            'settings.custom_questions.*.type' => ['nullable', Rule::in(['text', 'number', 'boolean', 'choice'])],
            'settings.custom_questions.*.required' => ['nullable', 'boolean'],
            'settings.custom_questions.*.options' => ['nullable', 'array'],
            'settings.enable_waitlist' => ['nullable', 'boolean'],
        ]);

        $event->fill(Arr::except($data, ['settings']));

        if (array_key_exists('event_start', $data)) {
            $event->event_start = Carbon::parse($data['event_start']);
        }

        if (array_key_exists('settings', $data)) {
            $event->settings = array_merge(
                [
                    'allow_plus_ones' => false,
                    'custom_questions' => [],
                    'enable_waitlist' => false,
                ],
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
}
