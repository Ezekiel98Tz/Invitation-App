<?php

namespace Tests\Feature\Api;

use App\Jobs\SendEntryCredentialJob;
use App\Models\Event;
use App\Models\Guest;
use App\Models\InvitationDelivery;
use App\Models\User;
use App\Services\CredentialIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * The entry credential must never be serialized into a queued notification/job.
 * Credential delivery is sent inline within SendEntryCredentialJob (which holds
 * only the guest id), so no nested SendQueuedNotifications carries the code.
 */
class CredentialQueueSecrecyTest extends TestCase
{
    use RefreshDatabase;

    public function test_credential_delivery_is_not_queued_as_a_notification_carrying_the_code(): void
    {
        Bus::fake();

        config([
            'mail.default' => 'array',
            'invitations.channels' => ['sms'],
            'delivery_lab.enabled' => true,
            'delivery_lab.sandbox' => true,
            'delivery_lab.recipients' => ['+255700000001'],
        ]);

        $owner = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $owner->id]);
        $guest = Guest::factory()->create([
            'event_id' => $event->id,
            'email' => null,
            'phone' => '+255700000001',
            'status' => 'accepted',
        ]);

        $credential = app(CredentialIssuer::class)->activate($guest);
        $code = (string) $credential->revealSecret();

        (new SendEntryCredentialJob($guest))->handle();

        // The notification must be sent inline — never dispatched to the queue.
        Bus::assertNotDispatched(SendQueuedNotifications::class);

        $delivery = InvitationDelivery::where('guest_id', $guest->id)
            ->where('kind', 'credential')
            ->where('channel', 'sms')
            ->firstOrFail();

        // Delivered inline, and the code is redacted from stored metadata.
        $this->assertSame('sent', $delivery->status);
        $this->assertStringNotContainsString($code, (string) json_encode($delivery->meta));
        $this->assertStringNotContainsString($code, (string) $delivery->error);
    }

    public function test_the_dispatched_job_payload_contains_no_plaintext_code(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $owner->id]);
        $guest = Guest::factory()->create(['event_id' => $event->id, 'status' => 'accepted']);

        $credential = app(CredentialIssuer::class)->activate($guest);
        $code = (string) $credential->revealSecret();

        // SerializesModels converts the Guest to an identifier; the code is never
        // a property of the job.
        $job = new SendEntryCredentialJob($guest);
        $serialized = serialize($job);

        $this->assertStringNotContainsString($code, $serialized);
        $this->assertStringNotContainsString('code_encrypted', $serialized);
    }
}
