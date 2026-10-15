<?php

namespace App\Support;

use App\Models\Event;
use Illuminate\Support\Arr;

/**
 * Single authoritative source for invitation design defaults and resolution.
 *
 * Both the designer (Api\InvitationDesignController) and the public invitation
 * (InviteController) resolve designs through this class so an unsaved event
 * renders identically everywhere. Palette/template definitions live in
 * config/templates.php.
 */
class InvitationDesign
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function templates(): array
    {
        return (array) config('templates.templates', []);
    }

    /**
     * @return array<string, string>
     */
    public static function fonts(): array
    {
        return (array) config('templates.fonts', []);
    }

    public static function defaultTemplateKey(): string
    {
        $templates = static::templates();
        $configured = config('templates.default');

        if (is_string($configured) && array_key_exists($configured, $templates)) {
            return $configured;
        }

        $key = array_key_first($templates);

        return is_string($key) ? $key : 'classic-white';
    }

    /**
     * Default design for the given template (or the configured default).
     *
     * @return array<string, mixed>
     */
    public static function defaults(?string $templateKey = null): array
    {
        $templates = static::templates();
        $key = ($templateKey !== null && array_key_exists($templateKey, $templates))
            ? $templateKey
            : static::defaultTemplateKey();

        $template = $templates[$key] ?? [];

        return [
            'template' => $key,
            'heading' => 'You are invited',
            'hosts' => '',
            'message' => '',
            'rsvp_note' => 'Kindly respond by the date below.',
            'background_image' => null,
            'font' => $template['font'] ?? 'serif',
            'colors' => $template['colors'] ?? [],
        ];
    }

    /**
     * Resolve the effective design for an event, applying defaults when no
     * design has been saved and deep-merging partial stored color maps.
     *
     * @return array<string, mixed>
     */
    public static function forEvent(Event $event): array
    {
        $stored = (array) Arr::get((array) ($event->settings ?? []), 'design', []);

        $base = static::defaults(
            isset($stored['template']) ? (string) $stored['template'] : null
        );

        return array_merge($base, $stored, [
            'colors' => array_merge(
                (array) ($base['colors'] ?? []),
                (array) ($stored['colors'] ?? [])
            ),
        ]);
    }
}
