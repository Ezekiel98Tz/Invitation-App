<?php

namespace Tests\Feature\Api;

use App\Models\Guest;
use App\Models\InvitationDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function delivery(array $attributes = []): InvitationDelivery
    {
        $guest = Guest::factory()->create();

        return InvitationDelivery::create(array_merge([
            'guest_id' => $guest->id,
            'kind' => 'invitation',
            'channel' => 'sms',
            'status' => 'sent',
            'provider_message_id' => 'prov-1',
        ], $attributes));
    }

    private function postCallback(string $channel, array $payload, string $secret = 'secret')
    {
        config()->set('invitations.webhook_secret', 'secret');

        return $this->postJson(route('webhooks.delivery.store', ['channel' => $channel]), $payload, [
            'X-Webhook-Secret' => $secret,
        ]);
    }

    public function test_valid_callback_advances_sent_to_delivered(): void
    {
        $delivery = $this->delivery(['status' => 'sent']);

        $this->postCallback('sms', ['provider_message_id' => 'prov-1', 'status' => 'delivered'])
            ->assertOk()
            ->assertJson(['updated' => true]);

        $delivery->refresh();
        $this->assertSame('delivered', $delivery->status);
        $this->assertNotNull($delivery->delivered_at);
    }

    public function test_callbacks_are_idempotent(): void
    {
        $delivery = $this->delivery(['status' => 'sent']);

        $this->postCallback('sms', ['provider_message_id' => 'prov-1', 'status' => 'delivered'])->assertOk();
        $deliveredAt = $delivery->fresh()->delivered_at;

        $second = $this->postCallback('sms', ['provider_message_id' => 'prov-1', 'status' => 'delivered'])
            ->assertOk()
            ->assertJson(['updated' => false, 'reason' => 'ignored']);

        $delivery->refresh();
        $this->assertSame('delivered', $delivery->status);
        $this->assertEquals($deliveredAt, $delivery->delivered_at);
    }

    public function test_terminal_delivery_cannot_regress(): void
    {
        $delivery = $this->delivery(['status' => 'delivered']);

        $this->postCallback('sms', ['provider_message_id' => 'prov-1', 'status' => 'sent'])
            ->assertOk()
            ->assertJson(['updated' => false, 'reason' => 'ignored']);

        $this->assertSame('delivered', $delivery->fresh()->status);
    }

    public function test_failed_delivery_cannot_be_revived_by_a_callback(): void
    {
        $delivery = $this->delivery(['status' => 'failed']);

        $this->postCallback('sms', ['provider_message_id' => 'prov-1', 'status' => 'delivered'])
            ->assertOk()
            ->assertJson(['updated' => false, 'reason' => 'ignored']);

        $this->assertSame('failed', $delivery->fresh()->status);
    }

    public function test_unknown_reference_is_rejected_and_changes_nothing(): void
    {
        $delivery = $this->delivery(['status' => 'sent', 'provider_message_id' => 'prov-1']);

        $this->postCallback('sms', ['provider_message_id' => 'does-not-exist', 'status' => 'delivered'])
            ->assertStatus(404);

        $this->assertSame('sent', $delivery->fresh()->status);
    }

    public function test_invalid_status_value_is_rejected(): void
    {
        $this->delivery(['status' => 'sent']);

        $this->postCallback('sms', ['provider_message_id' => 'prov-1', 'status' => 'read'])
            ->assertStatus(422);
    }

    public function test_invalid_secret_is_rejected_and_does_not_change_records(): void
    {
        $delivery = $this->delivery(['status' => 'sent']);

        config()->set('invitations.webhook_secret', 'secret');

        $this->postJson(route('webhooks.delivery.store', ['channel' => 'sms']), [
            'provider_message_id' => 'prov-1',
            'status' => 'delivered',
        ], ['X-Webhook-Secret' => 'wrong'])->assertStatus(401);

        $this->assertSame('sent', $delivery->fresh()->status);
    }

    public function test_missing_secret_fails_closed_and_cannot_be_used_for_simulation(): void
    {
        $delivery = $this->delivery(['status' => 'sent']);

        config()->set('invitations.webhook_secret', null);

        $this->postJson(route('webhooks.delivery.store', ['channel' => 'sms']), [
            'provider_message_id' => 'prov-1',
            'status' => 'delivered',
        ])->assertStatus(503);

        $this->assertSame('sent', $delivery->fresh()->status);
    }

    public function test_response_never_leaks_guest_information(): void
    {
        $guest = Guest::factory()->create();
        InvitationDelivery::create([
            'guest_id' => $guest->id,
            'kind' => 'invitation',
            'channel' => 'sms',
            'status' => 'sent',
            'provider_message_id' => 'prov-leak',
        ]);

        $response = $this->postCallback('sms', ['provider_message_id' => 'prov-leak', 'status' => 'delivered']);

        $this->assertStringNotContainsString((string) $guest->invite_token, (string) $response->getContent());
        $this->assertStringNotContainsString((string) $guest->email, (string) $response->getContent());
    }
}
