<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Security hardening for the token-scoped public invitation endpoints and the
 * operator verification endpoint.
 */
class InviteSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_guessing_many_tokens_from_one_client_shares_an_ip_bucket(): void
    {
        config([
            'invitations.rate_limits.invite_token_ip_per_minute' => 3,
            'invitations.rate_limits.invite_token_per_minute' => 100,
        ]);

        // Distinct, unknown tokens from the same client must all count against
        // the per-client-address cap (an attacker cannot reset by changing token).
        foreach (['aaaa', 'bbbb', 'cccc'] as $token) {
            $response = $this->getJson(route('api.invites.show', ['token' => $token]));
            $this->assertNotSame(429, $response->getStatusCode(), "token {$token} should be within the IP limit");
        }

        $this->getJson(route('api.invites.show', ['token' => 'dddd']))->assertStatus(429);
    }

    public function test_a_single_token_is_still_rate_limited(): void
    {
        config([
            'invitations.rate_limits.invite_token_ip_per_minute' => 100,
            'invitations.rate_limits.invite_token_per_minute' => 2,
        ]);

        $event = Event::factory()->create();
        $guest = Guest::factory()->create(['event_id' => $event->id]);
        $url = route('api.invites.show', ['token' => $guest->invite_token]);

        $this->getJson($url)->assertOk();
        $this->getJson($url)->assertOk();
        $this->getJson($url)->assertStatus(429);
    }

    public function test_verification_endpoint_is_rate_limited_per_operator(): void
    {
        config(['invitations.rate_limits.admin_read_per_minute' => 2]);

        $owner = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $owner->id]);
        $guest = Guest::factory()->create(['event_id' => $event->id]);

        $url = route('api.events.guests.verification', ['event' => $event->id, 'guest' => $guest->id]);

        $this->actingAs($owner)->getJson($url)->assertOk();
        $this->actingAs($owner)->getJson($url)->assertOk();
        $this->actingAs($owner)->getJson($url)->assertStatus(429);
    }
}
