<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_csrf_endpoint_responds_ok(): void
    {
        $this->getJson(route('api.csrf'))->assertOk()->assertJson(['ok' => true]);
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson(route('api.me'))->assertStatus(401);
    }

    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create();

        $this->postJson(route('api.auth.login'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->assertJsonPath('user.email', $user->email);

        $this->assertAuthenticated();
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        $user = User::factory()->create();

        $this->postJson(route('api.auth.login'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertGuest();
    }

    public function test_authenticated_user_can_fetch_profile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson(route('api.me'))
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonStructure(['user' => ['id', 'name', 'email', 'role', 'is_admin', 'is_staff']]);
    }

    public function test_user_can_logout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('api.auth.logout'))
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertGuest();
    }

    public function test_user_can_register(): void
    {
        $response = $this->postJson(route('api.auth.register'), [
            'name' => 'New User',
            'email' => 'new-user@example.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertStatus(201)->assertJsonPath('user.email', 'new-user@example.test');

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'new-user@example.test']);
    }
}
