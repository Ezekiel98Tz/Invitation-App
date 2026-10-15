<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Support\InvitationDesign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * Configuration-driven invitation design for an event.
 *
 * The design is persisted inside the event's `settings` JSON under the
 * `design` key so no schema migration is required. Templates themselves are
 * defined in config/templates.php and resolved through App\Support\InvitationDesign.
 */
class InvitationDesignController extends Controller
{
    private const HEX_COLOR = '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/';

    public function templates(): JsonResponse
    {
        return response()->json([
            'templates' => InvitationDesign::templates(),
            'fonts' => InvitationDesign::fonts(),
            'default_template' => InvitationDesign::defaultTemplateKey(),
        ]);
    }

    public function show(Request $request, Event $event): JsonResponse
    {
        $this->authorize('view', $event);

        return response()->json([
            'event' => $this->eventPayload($event),
            'design' => InvitationDesign::forEvent($event),
            'templates' => InvitationDesign::templates(),
            'fonts' => InvitationDesign::fonts(),
            'default_template' => InvitationDesign::defaultTemplateKey(),
        ]);
    }

    public function update(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);

        $data = $request->validate([
            'template' => ['required', 'string', Rule::in(array_keys(InvitationDesign::templates()))],
            'heading' => ['nullable', 'string', 'max:255'],
            'hosts' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:2000'],
            'rsvp_note' => ['nullable', 'string', 'max:255'],
            'background_image' => ['nullable', 'url', 'max:2048'],
            'font' => ['nullable', 'string', Rule::in(array_keys(InvitationDesign::fonts()))],
            'colors' => ['nullable', 'array'],
            'colors.background' => ['nullable', 'string', 'regex:'.self::HEX_COLOR],
            'colors.surface' => ['nullable', 'string', 'regex:'.self::HEX_COLOR],
            'colors.primary' => ['nullable', 'string', 'regex:'.self::HEX_COLOR],
            'colors.accent' => ['nullable', 'string', 'regex:'.self::HEX_COLOR],
            'colors.text' => ['nullable', 'string', 'regex:'.self::HEX_COLOR],
        ]);

        $existing = InvitationDesign::forEvent($event);

        $merged = array_merge($existing, Arr::except($data, ['colors']));

        // Deep-merge colors so a partial update (e.g. only accent) preserves
        // the rest of the palette. Null values are ignored to avoid wiping.
        if (array_key_exists('colors', $data)) {
            $incoming = array_filter((array) $data['colors'], fn ($value) => $value !== null);
            $merged['colors'] = array_merge((array) ($existing['colors'] ?? []), $incoming);
        }

        $settings = (array) ($event->settings ?? []);
        $settings['design'] = $merged;
        $event->settings = $settings;
        $event->save();

        return response()->json([
            'event' => $this->eventPayload($event),
            'design' => InvitationDesign::forEvent($event),
        ]);
    }

    private function eventPayload(Event $event): array
    {
        return [
            'id' => $event->id,
            'title' => $event->title,
            'description' => $event->description,
            'event_start' => $event->event_start?->toIso8601String(),
            'timezone' => $event->timezone,
            'venue' => $event->venue,
            'capacity' => $event->capacity,
        ];
    }
}
