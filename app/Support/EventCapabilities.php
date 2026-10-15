<?php

namespace App\Support;

/**
 * Explicit, validated configuration for optional event capabilities.
 *
 * A capability is an optional *module* an event may turn on (custom RSVP
 * questions, plus-ones, waitlists, check-in, households, contributions).
 * Capabilities are orthogonal to the event type: the type never restricts
 * which capabilities an event may enable.
 *
 * IMPORTANT: a capability is only marked `implemented` when the underlying
 * workflow actually exists. Flags for not-yet-implemented capabilities are
 * recognised (so the namespace is stable and documented) but can never be
 * enabled: enabling one is rejected by validation, and any stored value is
 * forced to false on read. This prevents a flag from being presented as a
 * working feature.
 *
 * Storage: capabilities live under the event's `settings.capabilities` JSON
 * object. Legacy flat settings keys that predate this structure
 * (`settings.allow_plus_ones`, a non-empty `settings.custom_questions`) are
 * honoured as a read fallback so events created before this change keep
 * working without any data migration.
 */
class EventCapabilities
{
    /**
     * Capability definitions. `implemented` reflects whether the workflow
     * exists today; do not set it to true without shipping the behaviour.
     *
     * @var array<string, array{label: string, description: string, implemented: bool, default: bool}>
     */
    private const DEFINITIONS = [
        'custom_questions' => [
            'label' => 'Custom RSVP questions',
            'description' => 'Ask guests custom questions when they RSVP.',
            'implemented' => true,
            'default' => false,
        ],
        'plus_ones' => [
            'label' => 'Plus-ones',
            'description' => 'Allow guests to bring additional attendees.',
            'implemented' => true,
            'default' => false,
        ],
        'waitlist' => [
            'label' => 'Waitlist',
            'description' => 'Waitlist guests when the event is at capacity.',
            'implemented' => false,
            'default' => false,
        ],
        'check_in' => [
            'label' => 'Check-in',
            'description' => 'Check invited guests in on arrival.',
            'implemented' => false,
            'default' => false,
        ],
        'households' => [
            'label' => 'Households',
            'description' => 'Group related guests into households.',
            'implemented' => false,
            'default' => false,
        ],
        'contributions' => [
            'label' => 'Contributions',
            'description' => 'Track contribution pledges and recorded contributions.',
            'implemented' => false,
            'default' => false,
        ],
    ];

    /**
     * @return array<string, array{label: string, description: string, implemented: bool, default: bool}>
     */
    public static function definitions(): array
    {
        return self::DEFINITIONS;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    /**
     * @return list<string>
     */
    public static function implementedKeys(): array
    {
        $keys = [];

        foreach (self::DEFINITIONS as $key => $definition) {
            if ($definition['implemented']) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    public static function isKnown(string $key): bool
    {
        return array_key_exists($key, self::DEFINITIONS);
    }

    public static function isImplemented(string $key): bool
    {
        return self::isKnown($key) && (bool) self::DEFINITIONS[$key]['implemented'];
    }

    /**
     * All known capabilities, defaulting to disabled.
     *
     * @return array<string, bool>
     */
    public static function defaults(): array
    {
        $defaults = [];

        foreach (self::DEFINITIONS as $key => $definition) {
            $defaults[$key] = (bool) $definition['default'];
        }

        return $defaults;
    }

    /**
     * Resolve the effective capability map for an event's settings.
     *
     * Precedence (lowest to highest):
     *   1. hard defaults (all false)
     *   2. event-type defaults (extension point; empty today)
     *   3. stored `settings.capabilities` values (implemented keys only)
     *   4. legacy settings fallback, only for keys not present in `capabilities`
     *
     * Not-yet-implemented capabilities are always forced to false.
     *
     * @param  array<string, mixed>  $settings
     * @param  array<string, bool>  $typeDefaults
     * @return array<string, bool>
     */
    public static function resolve(array $settings, array $typeDefaults = []): array
    {
        $capabilities = self::defaults();
        $stored = $settings['capabilities'] ?? null;
        $stored = is_array($stored) ? $stored : null;

        foreach ($typeDefaults as $key => $value) {
            if (self::isImplemented((string) $key)) {
                $capabilities[(string) $key] = (bool) $value;
            }
        }

        foreach (self::DEFINITIONS as $key => $definition) {
            if (! $definition['implemented']) {
                $capabilities[$key] = false;
                continue;
            }

            if ($stored !== null && array_key_exists($key, $stored)) {
                $capabilities[$key] = (bool) $stored[$key];
                continue;
            }

            $capabilities[$key] = self::legacyValue($key, $settings);
        }

        // Force not-yet-implemented capabilities off regardless of input.
        foreach (self::DEFINITIONS as $key => $definition) {
            if (! $definition['implemented']) {
                $capabilities[$key] = false;
            }
        }

        return $capabilities;
    }

    /**
     * Legacy settings fallback for a capability that predates the explicit
     * `settings.capabilities` structure.
     *
     * @param  array<string, mixed>  $settings
     */
    private static function legacyValue(string $key, array $settings): bool
    {
        return match ($key) {
            'plus_ones' => (bool) ($settings['allow_plus_ones'] ?? false),
            'custom_questions' => ! empty($settings['custom_questions']),
            // `enable_waitlist` is a legacy placeholder whose workflow does not
            // exist, so it never enables the waitlist capability.
            default => false,
        };
    }
}
