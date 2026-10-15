<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiInvitationDesignTest extends TestCase
{
    use RefreshDatabase;

    public function test_templates_are_listed(): void
    {
        $this->getJson(route('api.templates'))
            ->assertOk()
            ->assertJsonStructure(['templates', 'fonts'])
            ->assertJsonCount(5, 'templates');
    }

    public function test_admin_can_view_and_update_event_invitation_design(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->getJson(route('api.events.invitation.show', $event))
            ->assertOk()
            ->assertJsonStructure(['event', 'design' => ['template', 'colors', 'font'], 'templates']);

        $this->actingAs($user)
            ->putJson(route('api.events.invitation.update', $event), [
                'template' => 'midnight-gold',
                'heading' => 'Join us',
                'hosts' => 'The Smiths',
                'message' => 'Dinner and dancing',
                'font' => 'modern',
                'colors' => ['accent' => '#ffcc00'],
            ])
            ->assertOk()
            ->assertJsonPath('design.template', 'midnight-gold')
            ->assertJsonPath('design.heading', 'Join us')
            ->assertJsonPath('design.colors.accent', '#ffcc00');

        $this->assertSame('midnight-gold', $event->fresh()->settings['design']['template']);
    }

    public function test_design_update_rejects_unknown_template(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->putJson(route('api.events.invitation.update', $event), ['template' => 'does-not-exist'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('template');
    }

    public function test_user_cannot_edit_another_users_design(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($other)
            ->putJson(route('api.events.invitation.update', $event), ['template' => 'classic-white'])
            ->assertStatus(403);
    }

    public function test_partial_color_update_preserves_other_colors(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->putJson(route('api.events.invitation.update', $event), [
                'template' => 'classic-white',
                'colors' => [
                    'background' => '#ffffff',
                    'surface' => '#f8fafc',
                    'primary' => '#1f2937',
                    'accent' => '#b45309',
                    'text' => '#111827',
                ],
            ])
            ->assertOk();

        $this->actingAs($user)
            ->putJson(route('api.events.invitation.update', $event), [
                'template' => 'classic-white',
                'colors' => ['accent' => '#ff0000'],
            ])
            ->assertOk()
            ->assertJsonPath('design.colors.accent', '#ff0000')
            ->assertJsonPath('design.colors.background', '#ffffff')
            ->assertJsonPath('design.colors.text', '#111827');
    }

    public function test_unsaved_design_defaults_to_configured_template(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $user->id, 'settings' => []]);

        $default = config('templates.default');
        $expectedBackground = config("templates.templates.$default.colors.background");

        $this->actingAs($user)
            ->getJson(route('api.events.invitation.show', $event))
            ->assertOk()
            ->assertJsonPath('design.template', $default)
            ->assertJsonPath('design.colors.background', $expectedBackground);
    }

    public function test_invalid_color_value_is_rejected(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->putJson(route('api.events.invitation.update', $event), [
                'template' => 'classic-white',
                'colors' => ['accent' => 'not-a-color'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('colors.accent');
    }

    public function test_invalid_background_image_is_rejected(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->putJson(route('api.events.invitation.update', $event), [
                'template' => 'classic-white',
                'background_image' => 'not a url',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('background_image');
    }
}
