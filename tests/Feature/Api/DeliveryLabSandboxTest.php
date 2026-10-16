<?php

namespace Tests\Feature\Api;

use App\Jobs\SendInvitationJob;
use App\Models\Event;
use App\Models\Guest;
use App\Models\InvitationDelivery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeliveryLabSandboxTest extends TestCase
{
    use RefreshDatabase;

    private function guest(User $owner, array $attributes = []): Guest
    {
        $event = Event::factory()->create(['user_id' => $owner->id]);

        return Guest::factory()->create(array_merge([
            'event_id' => $event->id,
            'name' => 'Ada',
            'email' => 'ada@example.test',
            'phone' => '+255700000001',
            'sent_at' => null,
        ], $attributes));
    }

    private function sandboxConfig(array $attributes = []): void
    {
        config(array_merge([
            'mail.default' => 'array',
            'delivery_lab.enabled' => true,
            'delivery_lab.sandbox' => true,
            'delivery_lab.recipients' => ['ada@example.test', '+255700000001'],
            'invitations.channels' => ['sms'],
            'invitations.sms.endpoint' => 'https://sms.example.test/send',
        ], $attributes));
    }

    private function testSendUrl(Guest $guest): string
    {
        return route('api.delivery-lab.test-send', ['event' => $guest->event_id, 'guest' => $guest->id]);
    }

    public function test_test_send_requires_authentication_and_guards_account_boundaries(): void
    {
        $owner = User::factory()->create();
        $guest = $this->guest($owner);
        $intruder = User::factory()->create();

        $this->sandboxConfig();

        $this->postJson($this->testSendUrl($guest), ['kind' => 'invitation', 'channel' => 'sms'])->assertStatus(401);

        $this->actingAs($intruder)
            ->postJson($this->testSendUrl($guest), ['kind' => 'invitation', 'channel' => 'sms'])
            ->assertForbidden();
    }

    public function test_test_send_requires_the_lab_to_be_enabled(): void
    {
        config(['delivery_lab.enabled' => false]);

        $owner = User::factory()->create();
        $guest = $this->guest($owner);

        $this->actingAs($owner)
            ->postJson($this->testSendUrl($guest), ['kind' => 'invitation', 'channel' => 'sms'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('channel');
    }

    public function test_sandbox_test_send_captures_without_any_http_request(): void
    {
        Http::fake();
        $this->sandboxConfig();

        $owner = User::factory()->create();
        $guest = $this->guest($owner);

        $response = $this->actingAs($owner)
            ->postJson($this->testSendUrl($guest), ['kind' => 'invitation', 'channel' => 'sms'])
            ->assertOk()
            ->assertJsonPath('outcome', 'captured')
            ->assertJsonPath('delivery.mode', 'sandbox')
            ->assertJsonPath('delivery.simulated', true)
            ->assertJsonPath('delivery.status', 'sent')
            ->assertJsonPath('delivery.blocked', false);

        // Captured, not sent to a provider; no fabricated provider reference.
        $this->assertStringContainsString($guest->event->title, (string) $response->json('delivery.captured_message'));
        $this->assertStringContainsString("\n\n", (string) $response->json('delivery.captured_message'));
        $this->assertNull($response->json('delivery.provider_reference'));

        Http::assertNothingSent();

        $delivery = InvitationDelivery::latest('id')->first();
        $this->assertTrue($delivery->meta['sandbox']);
        $this->assertTrue($delivery->meta['lab']['test']);
        $this->assertSame('captured', $delivery->meta['lab']['outcome']);
        $this->assertStringContainsString($guest->event->title, (string) $delivery->meta['captured']['message']);
        $this->assertStringContainsString($guest->invite_url_absolute, (string) $delivery->meta['captured']['message']);
    }

    public function test_blocked_recipient_is_recorded_and_never_sent(): void
    {
        Http::fake();
        $this->sandboxConfig(['delivery_lab.recipients' => ['someone-else@example.test']]);

        $owner = User::factory()->create();
        $guest = $this->guest($owner);

        $response = $this->actingAs($owner)
            ->postJson($this->testSendUrl($guest), ['kind' => 'invitation', 'channel' => 'sms'])
            ->assertStatus(422)
            ->assertJsonPath('outcome', 'blocked');

        Http::assertNothingSent();

        $delivery = InvitationDelivery::latest('id')->first();
        $this->assertSame('failed', $delivery->status);
        $this->assertTrue($delivery->meta['lab']['blocked']);
        $this->assertSame('blocked', $delivery->meta['lab']['outcome']);
        $this->assertStringContainsString('Blocked', (string) $delivery->error);

        // The blocked attempt is observable in the dashboard counts.
        $this->actingAs($owner)
            ->getJson(route('api.delivery-lab.index'))
            ->assertOk()
            ->assertJsonPath('counts.blocked', 1);
    }

    public function test_sandbox_simulated_failure_is_recorded_as_failed_not_delivered(): void
    {
        Http::fake();
        $this->sandboxConfig(['delivery_lab.sandbox_outcome' => 'failed']);

        $owner = User::factory()->create();
        $guest = $this->guest($owner);

        $this->actingAs($owner)
            ->postJson($this->testSendUrl($guest), ['kind' => 'invitation', 'channel' => 'sms'])
            ->assertOk()
            ->assertJsonPath('outcome', 'failed')
            ->assertJsonPath('delivery.status', 'failed');

        Http::assertNothingSent();

        $delivery = InvitationDelivery::latest('id')->first();
        $this->assertSame('failed', $delivery->status);
        $this->assertSame('simulated provider failure (sandbox).', strtolower((string) $delivery->error));
        $this->assertSame('sandbox_simulated', $delivery->meta['provider']['reason']);
        $this->assertNull($delivery->delivered_at);
    }

    public function test_provider_mode_test_send_uses_http_and_is_labelled_accepted(): void
    {
        Http::fake(['sms.example.test/*' => Http::response(['message_id' => 'prov-1'], 200)]);

        $this->sandboxConfig([
            'delivery_lab.sandbox' => false,
            'invitations.sms.token' => 'secret-token',
        ]);

        $owner = User::factory()->create();
        $guest = $this->guest($owner);

        $response = $this->actingAs($owner)
            ->postJson($this->testSendUrl($guest), ['kind' => 'invitation', 'channel' => 'sms'])
            ->assertOk()
            ->assertJsonPath('outcome', 'accepted')
            ->assertJsonPath('delivery.mode', 'provider')
            ->assertJsonPath('delivery.simulated', false)
            ->assertJsonPath('delivery.provider_reference', 'prov-1');

        Http::assertSentCount(1);
        $this->assertStringNotContainsString('secret-token', (string) $response->getContent());
    }

    public function test_repeated_test_send_is_debounced(): void
    {
        Http::fake();
        $this->sandboxConfig();

        $owner = User::factory()->create();
        $guest = $this->guest($owner);

        $this->actingAs($owner)
            ->postJson($this->testSendUrl($guest), ['kind' => 'invitation', 'channel' => 'sms'])
            ->assertOk();

        // An immediate second request falls inside the debounce window.
        $this->actingAs($owner)
            ->postJson($this->testSendUrl($guest), ['kind' => 'invitation', 'channel' => 'sms'])
            ->assertStatus(429);
    }

    public function test_bulk_send_in_sandbox_makes_no_external_requests(): void
    {
        Http::fake();
        $this->sandboxConfig();

        $owner = User::factory()->create();
        $guest = $this->guest($owner);

        (new SendInvitationJob($guest))->handle();

        Http::assertNothingSent();

        $delivery = InvitationDelivery::where('guest_id', $guest->id)->where('kind', 'invitation')->latest('id')->first();
        $this->assertNotNull($delivery);
        $this->assertSame('sent', $delivery->status);
        $this->assertTrue($delivery->meta['sandbox']);
        $this->assertNotNull($guest->fresh()->sent_at);
    }
}
