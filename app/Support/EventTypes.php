<?php

namespace App\Support;

/**
 * Event type taxonomy.
 *
 * Event types are a stable string key backed by configuration
 * (config/event_types.php). They provide presentation metadata (label,
 * description) and an optional `default_capabilities` extension point. They do
 * NOT gate capabilities: any capability may be enabled on any event.
 *
 * Unknown or missing keys resolve to the configured default so that events
 * created before the taxonomy existed (and any malformed input that slipped
 * past validation) still behave predictably.
 */
class EventTypes
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return (array) config('event_types.types', []);
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(static::all());
    }

    public static function has(?string $key): bool
    {
        return $key !== null && array_key_exists($key, static::all());
    }

    public static function default(): string
    {
        $configured = config('event_types.default');

        if (is_string($configured) && static::has($configured)) {
            return $configured;
        }

        $first = array_key_first(static::all());

        return is_string($first) ? $first : 'custom';
    }

    /**
     * Resolve an arbitrary (possibly null/unknown) key to a known type key.
     */
    public static function resolve(?string $key): string
    {
        return static::has($key) ? (string) $key : static::default();
    }

    /**
     * @return array<string, mixed>
     */
    public static function definition(string $key): array
    {
        return (array) (static::all()[$key] ?? []);
    }

    public static function label(string $key): string
    {
        $label = static::definition(static::resolve($key))['label'] ?? null;

        return is_string($label) && $label !== '' ? $label : static::resolve($key);
    }

    /**
     * Type-driven capability defaults (extension point; empty today).
     *
     * @return array<string, bool>
     */
    public static function defaultCapabilities(string $key): array
    {
        $defaults = static::definition(static::resolve($key))['default_capabilities'] ?? [];

        return is_array($defaults) ? $defaults : [];
    }

    /**
     * Taxonomy payload for the API.
     *
     * @return array{default: string, types: array<string, array<string, mixed>>}
     */
    public static function taxonomy(): array
    {
        return [
            'default' => static::default(),
            'types' => static::all(),
        ];
    }
}
