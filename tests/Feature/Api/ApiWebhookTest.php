<?php

namespace Tests\Feature\Api;

use App\Models\Guest;
use App\Models\InvitationDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_fails_closed_when_secret_is_not_configured(): void
    {
        config()->set('invitations.webhook_secret', null);

        $this->postJson(route('webhooks.delivery.store', ['channel' => 'sms']), [
            'provider_message_id' => 'abc',
            'status' => 'delivered',
        ])->assertStatus(503);
    }

    public function test_webhook_rejects_invalid_secret(): void
    {
        config()->set('invitations.webhook_secret', 'secret');

        $this->postJson(route('webhooks.delivery.store', ['channel' => 'sms']), [
            'provider_message_id' => 'abc',
            'status' => 'delivered',
        ], ['X-Webhook-Secret' => 'wrong'])->assertStatus(401);
    }

    public function test_webhook_marks_delivery_delivered_with_valid_secret(): void
    {
        config()->set('invitations.webhook_secret', 'secret');

        $guest = Guest::factory()->create();
        $delivery = InvitationDelivery::create([
            'guest_id' => $guest->id,
            'kind' => 'invitation',
            'channel' => 'sms',
            'status' => 'sent',
            'provider_message_id' => 'prov-1',
        ]);

        $this->postJson(route('webhooks.delivery.store', ['channel' => 'sms']), [
            'provider_message_id' => 'prov-1',
            'status' => 'delivered',
        ], ['X-Webhook-Secret' => 'secret'])->assertOk()->assertJson(['updated' => true]);

        $delivery->refresh();
        $this->assertSame('delivered', $delivery->status);
        $this->assertNotNull($delivery->delivered_at);
    }
}
