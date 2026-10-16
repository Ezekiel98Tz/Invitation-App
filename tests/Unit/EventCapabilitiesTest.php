<?php

namespace Tests\Unit;

use App\Support\EventCapabilities;
use App\Support\EventTypes;
use Tests\TestCase;

class EventCapabilitiesTest extends TestCase
{
    public function test_defaults_cover_every_capability_and_disable_them(): void
    {
        $defaults = EventCapabilities::defaults();

        $this->assertSame(EventCapabilities::keys(), array_keys($defaults));

        foreach ($defaults as $enabled) {
            $this->assertFalse($enabled);
        }
    }

    public function test_only_shipped_workflows_are_marked_implemented(): void
    {
        $this->assertSame(
            ['custom_questions', 'plus_ones', 'check_in', 'contributions'],
            EventCapabilities::implementedKeys(),
        );

        $this->assertTrue(EventCapabilities::isImplemented('plus_ones'));
        $this->assertTrue(EventCapabilities::isImplemented('check_in'));
        $this->assertTrue(EventCapabilities::isImplemented('contributions'));
        $this->assertFalse(EventCapabilities::isImplemented('households'));
        $this->assertFalse(EventCapabilities::isKnown('not_a_capability'));
    }

    public function test_implemented_capabilities_are_disabled_by_default_but_can_be_enabled(): void
    {
        // Default-off: no explicit flag and no type default means disabled.
        $defaults = EventCapabilities::resolve([]);
        $this->assertFalse($defaults['check_in']);
        $this->assertFalse($defaults['contributions']);

        $enabled = EventCapabilities::resolve([
            'capabilities' => ['check_in' => true, 'contributions' => true],
        ]);

        $this->assertTrue($enabled['check_in']);
        $this->assertTrue($enabled['contributions']);
    }

    public function test_type_defaults_can_opt_an_implemented_capability_in(): void
    {
        $caps = EventCapabilities::resolve([], ['contributions' => true]);

        $this->assertTrue($caps['contributions']);
        $this->assertFalse($caps['check_in']);
    }

    public function test_explicit_capability_flags_are_resolved(): void
    {
        $caps = EventCapabilities::resolve([
            'capabilities' => ['plus_ones' => true, 'custom_questions' => true],
        ]);

        $this->assertTrue($caps['plus_ones']);
        $this->assertTrue($caps['custom_questions']);
    }

    public function test_legacy_plus_ones_setting_enables_the_capability(): void
    {
        $this->assertTrue(EventCapabilities::resolve(['allow_plus_ones' => true])['plus_ones']);
        $this->assertFalse(EventCapabilities::resolve(['allow_plus_ones' => false])['plus_ones']);
    }

    public function test_legacy_custom_questions_array_enables_the_capability(): void
    {
        $caps = EventCapabilities::resolve([
            'custom_questions' => [['key' => 'meal', 'type' => 'text']],
        ]);

        $this->assertTrue($caps['custom_questions']);
    }

    public function test_explicit_capabilities_take_precedence_over_legacy_settings(): void
    {
        $caps = EventCapabilities::resolve([
            'allow_plus_ones' => true,
            'capabilities' => ['plus_ones' => false],
        ]);

        $this->assertFalse($caps['plus_ones']);
    }

    public function test_not_yet_implemented_capabilities_are_always_forced_off(): void
    {
        $caps = EventCapabilities::resolve([
            'capabilities' => [
                'households' => true,
                'waitlist' => true,
                // Legacy placeholder must never enable the waitlist either.
                'enable_waitlist' => true,
            ],
            'enable_waitlist' => true,
        ], ['households' => true, 'waitlist' => true]);

        $this->assertFalse($caps['households']);
        $this->assertFalse($caps['waitlist']);
    }

    public function test_event_type_taxonomy_is_configuration_driven(): void
    {
        $this->assertContains('wedding', EventTypes::keys());
        $this->assertContains('send_off', EventTypes::keys());
        $this->assertContains('kitchen_party', EventTypes::keys());
        $this->assertContains('birthday', EventTypes::keys());
        $this->assertContains('graduation', EventTypes::keys());
        $this->assertContains('corporate', EventTypes::keys());
        $this->assertContains('custom', EventTypes::keys());

        $this->assertSame('custom', EventTypes::default());
        $this->assertSame('custom', EventTypes::resolve(null));
        $this->assertSame('custom', EventTypes::resolve('not_a_type'));
        $this->assertSame('wedding', EventTypes::resolve('wedding'));
        $this->assertIsArray(EventTypes::defaultCapabilities('wedding'));
    }
}
