<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpaFallbackTest extends TestCase
{
    use RefreshDatabase;

    private function requireBuild(): void
    {
        if (! is_file(public_path('app/index.html'))) {
            $this->markTestSkipped('Frontend build not present (run: cd frontend && npm run build).');
        }
    }

    public function test_root_serves_the_react_application(): void
    {
        $this->requireBuild();

        $this->get('/')->assertOk()->assertSee('id="root"', false);
    }

    public function test_react_login_route_serves_the_spa(): void
    {
        $this->requireBuild();

        $this->get('/login')->assertOk()->assertSee('id="root"', false);
    }

    public function test_react_register_route_serves_the_spa(): void
    {
        $this->requireBuild();

        $this->get('/register')->assertOk()->assertSee('id="root"', false);
    }

    public function test_dashboard_requires_authentication_then_serves_the_spa(): void
    {
        $this->requireBuild();

        $this->get('/dashboard')->assertRedirect('/login');

        $user = User::factory()->create();
        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('id="root"', false);
    }

    public function test_event_route_serves_the_spa_for_authenticated_users(): void
    {
        $this->requireBuild();

        $user = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $user->id]);

        $this->get('/events/'.$event->id)->assertRedirect('/login');
        $this->actingAs($user)->get('/events/'.$event->id)->assertOk()->assertSee('id="root"', false);
    }

    public function test_client_side_route_falls_back_to_the_spa(): void
    {
        $this->requireBuild();

        $user = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get('/events/'.$event->id.'/invitation')
            ->assertOk()
            ->assertSee('id="root"', false);
    }

    public function test_legacy_invitation_route_is_preserved(): void
    {
        $response = $this->get('/invite/definitely-not-a-real-token');

        $response->assertStatus(404);
        $this->assertStringNotContainsString('id="root"', $response->getContent());
    }

    public function test_api_routes_are_not_captured_by_the_spa(): void
    {
        $this->getJson('/api/v1/templates')->assertOk()->assertJsonStructure(['templates', 'fonts']);
        $this->getJson('/api/v1/does-not-exist')->assertStatus(404);
    }

    public function test_legacy_app_path_redirects_to_root(): void
    {
        $this->get('/app/login')->assertRedirect('/login');
        $this->get('/app')->assertRedirect('/');
    }
}
