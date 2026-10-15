<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\Guest;
use App\Models\Rsvp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiInviteTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_invite_payload_is_returned_for_a_token(): void
    {
        $event = Event::factory()->create();
        $guest = Guest::factory()->create(['event_id' => $event->id]);

        $this->getJson(route('api.invites.show', ['token' => $guest->invite_token]))
            ->assertOk()
            ->assertJsonPath('guest.name', $guest->name)
            ->assertJsonPath('event.id', $event->id)
            ->assertJsonStructure(['guest', 'event', 'settings' => ['allow_plus_ones', 'custom_questions']]);
    }

    public function test_public_invite_returns_resolved_default_design(): void
    {
        $event = Event::factory()->create(['settings' => []]);
        $guest = Guest::factory()->create(['event_id' => $event->id]);

        $this->getJson(route('api.invites.show', ['token' => $guest->invite_token]))
            ->assertOk()
            ->assertJsonPath('settings.design.template', config('templates.default'))
            ->assertJsonStructure(['settings' => ['design' => ['template', 'colors', 'font']]]);
    }

    public function test_guest_can_submit_rsvp_via_api(): void
    {
        $event = Event::factory()->create([
            'settings' => [
                'allow_plus_ones' => true,
                'custom_questions' => [],
                'enable_waitlist' => false,
            ],
        ]);
        $guest = Guest::factory()->create(['event_id' => $event->id, 'status' => 'pending']);

        $this->postJson(route('api.invites.store', ['token' => $guest->invite_token]), [
            'status' => 'accepted',
            'attending_count' => 2,
        ])->assertOk()->assertJsonPath('accepted', true);

        $this->assertSame('accepted', $guest->fresh()->status);
        $this->assertSame(2, Rsvp::where('guest_id', $guest->id)->value('attending_count'));
    }

    public function test_expired_invite_returns_410(): void
    {
        $event = Event::factory()->create(['event_start' => now()->subDay()]);
        $guest = Guest::factory()->create(['event_id' => $event->id]);

        $this->getJson(route('api.invites.show', ['token' => $guest->invite_token]))
            ->assertStatus(410);
    }

    public function test_qr_endpoint_returns_svg_for_both_web_and_api(): void
    {
        // SVG rendering must not depend on the imagick extension.
        $event = Event::factory()->create();
        $guest = Guest::factory()->create(['event_id' => $event->id]);

        $web = $this->get(route('invites.qr', ['token' => $guest->invite_token]));
        $web->assertOk();
        $this->assertStringContainsString('image/svg+xml', (string) $web->headers->get('Content-Type'));
        $this->assertStringContainsString('<svg', (string) $web->getContent());

        $api = $this->get(route('api.invites.qr', ['token' => $guest->invite_token]));
        $api->assertOk();
        $this->assertStringContainsString('image/svg+xml', (string) $api->headers->get('Content-Type'));
        $this->assertStringContainsString('<svg', (string) $api->getContent());
    }

    public function test_qr_code_is_guest_specific(): void
    {
        $event = Event::factory()->create();
        $first = Guest::factory()->create(['event_id' => $event->id]);
        $second = Guest::factory()->create(['event_id' => $event->id]);

        $svgA = (string) $this->get(route('api.invites.qr', ['token' => $first->invite_token]))->getContent();
        $svgB = (string) $this->get(route('api.invites.qr', ['token' => $second->invite_token]))->getContent();

        $this->assertNotSame('', $svgA);
        $this->assertNotSame($svgA, $svgB);
    }

    public function test_qr_endpoint_returns_404_for_expired_invitation(): void
    {
        $event = Event::factory()->create(['event_start' => now()->subDay()]);
        $guest = Guest::factory()->create(['event_id' => $event->id]);

        $this->get(route('api.invites.qr', ['token' => $guest->invite_token]))->assertStatus(404);
    }

    public function test_unavailable_event_returns_clean_responses_and_never_500(): void
    {
        $event = Event::factory()->create();
        $guest = Guest::factory()->create(['event_id' => $event->id]);

        // Soft-delete the event; the guest row remains but resolves to no event.
        $event->delete();

        // Browser page -> 410 (clean "no longer available" page).
        $this->get(route('invites.show', ['token' => $guest->invite_token]))->assertStatus(410);

        // Public API read -> 410 JSON.
        $this->getJson(route('api.invites.show', ['token' => $guest->invite_token]))->assertStatus(410);

        // RSVP submission -> 410 JSON, not 500.
        $this->postJson(route('api.invites.store', ['token' => $guest->invite_token]), ['status' => 'accepted'])
            ->assertStatus(410);

        // QR -> 404.
        $this->get(route('api.invites.qr', ['token' => $guest->invite_token]))->assertStatus(404);
    }
}
