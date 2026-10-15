<?php

namespace Tests\Feature\Api;

use App\Jobs\SendInvitationJob;
use App\Models\Event;
use App\Models\Guest;
use App\Models\InvitationDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Throwable;

class DeliveryProviderResultTest extends TestCase
{
    use RefreshDatabase;

    private function smsGuest(): Guest
    {
        $event = Event::factory()->create();

        return Guest::factory()->create([
            'event_id' => $event->id,
            'email' => null,
            'phone' => '+255700000001',
            'sent_at' => null,
        ]);
    }

    private function configureSmsOnly(): void
    {
        config([
            'mail.default' => 'array',
            'invitations.channels' => ['sms'],
            'invitations.sms.endpoint' => 'https://sms.example.test/send',
            'invitations.sms.token' => 'secret-token-value',
            'invitations.sms.sender' => 'Studio',
        ]);
    }

    private function smsDelivery(Guest $guest): InvitationDelivery
    {
        return InvitationDelivery::where('guest_id', $guest->id)->where('channel', 'sms')->firstOrFail();
    }

    private function runJobSwallowingExceptions(Guest $guest): void
    {
        try {
            (new SendInvitationJob($guest))->handle();
        } catch (Throwable $e) {
            // expected for failure paths
        }
    }

    public function test_provider_acceptance_captures_reference_id_and_is_not_delivered(): void
    {
        Http::fake(['sms.example.test/*' => Http::response(['message_id' => 'ref-123'], 200)]);
        $this->configureSmsOnly();
        $guest = $this->smsGuest();

        (new SendInvitationJob($guest))->handle();

        $delivery = $this->smsDelivery($guest);
        $this->assertSame('sent', $delivery->status);           // accepted/submitted
        $this->assertNotSame('delivered', $delivery->status);   // NOT confirmed delivery
        $this->assertSame('ref-123', $delivery->provider_message_id);
        $this->assertNotNull($delivery->sent_at);
        $this->assertNull($delivery->delivered_at);
        $this->assertNull($delivery->error);
        $this->assertTrue($delivery->meta['provider']['accepted']);
        $this->assertSame(200, $delivery->meta['provider']['http_status']);

        $this->assertSame(0, InvitationDelivery::where('status', 'delivered')->count());
    }

    public function test_provider_rejection_is_recorded_as_failed_with_a_sanitized_error(): void
    {
        Http::fake(['sms.example.test/*' => Http::response(['error' => 'BAD_REQUEST_INTERNAL_DETAIL'], 422)]);
        $this->configureSmsOnly();
        $guest = $this->smsGuest();

        $this->runJobSwallowingExceptions($guest);

        $delivery = $this->smsDelivery($guest);
        $this->assertSame('failed', $delivery->status);
        $this->assertNull($delivery->sent_at);
        $this->assertSame('The SMS provider returned an error response.', $delivery->error);
        $this->assertStringNotContainsString('BAD_REQUEST_INTERNAL_DETAIL', (string) $delivery->error);
        $this->assertStringNotContainsString('BAD_REQUEST_INTERNAL_DETAIL', (string) json_encode($delivery->meta));
        $this->assertSame(422, $delivery->meta['provider']['http_status']);
    }

    public function test_network_failure_is_recorded_as_failed_and_retryable(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });
        $this->configureSmsOnly();
        $guest = $this->smsGuest();

        $this->runJobSwallowingExceptions($guest);

        $delivery = $this->smsDelivery($guest);
        $this->assertSame('failed', $delivery->status);
        $this->assertSame('The SMS provider could not be reached.', $delivery->error);
        $this->assertSame('network_error', $delivery->meta['provider']['reason']);
        $this->assertNull($delivery->sent_at);
    }

    public function test_provider_server_error_throws_so_the_queue_can_retry(): void
    {
        Http::fake(['sms.example.test/*' => Http::response('upstream', 500)]);
        $this->configureSmsOnly();
        $guest = $this->smsGuest();

        $this->expectException(Throwable::class);

        (new SendInvitationJob($guest))->handle();
    }

    public function test_malformed_successful_response_is_accepted_without_inventing_a_reference(): void
    {
        Http::fake(['sms.example.test/*' => Http::response('not-json', 200)]);
        $this->configureSmsOnly();
        $guest = $this->smsGuest();

        (new SendInvitationJob($guest))->handle();

        $delivery = $this->smsDelivery($guest);
        $this->assertSame('sent', $delivery->status);
        $this->assertNull($delivery->provider_message_id);
        $this->assertFalse($delivery->meta['provider']['has_reference']);
        $this->assertNull($delivery->error);
    }

    public function test_credentials_and_recipient_are_never_stored(): void
    {
        Http::fake(['sms.example.test/*' => Http::response(['error' => 'nope'], 400)]);
        $this->configureSmsOnly();
        $guest = $this->smsGuest();

        $this->runJobSwallowingExceptions($guest);

        $delivery = $this->smsDelivery($guest);
        $haystack = (string) $delivery->error.' '.json_encode($delivery->meta);

        $this->assertStringNotContainsString('secret-token-value', $haystack);
        $this->assertStringNotContainsString('+255700000001', $haystack);
        $this->assertStringNotContainsString('Authorization', $haystack);
        $this->assertStringNotContainsString('Bearer', $haystack);
    }
}
