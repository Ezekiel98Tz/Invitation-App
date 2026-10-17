<?php

namespace Tests\Feature\Api;

use App\Jobs\SendDeliveryJob;
use App\Jobs\SendInvitationJob;
use App\Jobs\SendReminderJob;
use App\Models\Event;
use App\Models\Guest;
use App\Models\InvitationDelivery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GuestCommunicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mail.default' => 'array',
            'invitations.channels' => ['mail'],
            'invitations.sms.endpoint' => null,
            'invitations.whatsapp.endpoint' => null,
            'delivery_lab.enabled' => false,
        ]);
        Cache::flush();
    }

    private function owner(): User
    {
        return User::factory()->create();
    }

    private function event(User $owner): Event
    {
        return Event::factory()->create(['user_id' => $owner->id]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function guest(Event $event, array $attributes = []): Guest
    {
        return Guest::factory()->create(array_merge([
            'event_id' => $event->id,
            'email' => 'guest@example.test',
            'phone' => '+255700000001',
            'status' => 'pending',
            'sent_at' => null,
            'reminded_at' => null,
        ], $attributes));
    }

    public function test_channel_options_only_report_configured_and_available_channels(): void
    {
        config([
            'invitations.channels' => ['mail', 'sms'],
            'invitations.sms.endpoint' => 'https://sms.example.test/send',
        ]);

        $owner = $this->owner();
        $event = $this->event($owner);

        $response = $this->actingAs($owner)
            ->getJson(route('api.events.communication.channels', $event))
            ->assertOk();

        $channels = collect($response->json('channels'))->keyBy('key');

        $this->assertTrue($channels['mail']['available']);
        $this->assertTrue($channels['sms']['available']);
        $this->assertFalse($channels['whatsapp']['available']);
        $this->assertFalse($channels['whatsapp']['configured']);
        $this->assertStringContainsString('not enabled', $channels['whatsapp']['reason']);
        $this->assertStringNotContainsString('sms.example.test', $response->getContent());
        $this->assertFalse($response->json('delivery_lab_restricted'));
    }

    public function test_communication_endpoints_are_event_scoped(): void
    {
        Queue::fake();
        $owner = $this->owner();
        $other = $this->owner();
        $event = $this->event($owner);
        $guest = $this->guest($event);

        $this->actingAs($other)
            ->getJson(route('api.events.communication.channels', $event))
            ->assertForbidden();

        $this->actingAs($other)
            ->postJson(route('api.events.invitations.send', $event), [
                'channel' => 'mail',
                'guest_ids' => [$guest->id],
            ])
            ->assertForbidden();

        $this->actingAs($other)
            ->postJson(route('api.events.guests.reminders', $event), [
                'channel' => 'mail',
                'guest_ids' => [$guest->id],
            ])
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_individual_invitation_resend_queues_the_selected_channel_and_records_it(): void
    {
        Queue::fake();
        config([
            'invitations.channels' => ['mail', 'sms'],
            'invitations.sms.endpoint' => 'https://sms.example.test/send',
        ]);

        $owner = $this->owner();
        $event = $this->event($owner);
        $guest = $this->guest($event, ['sent_at' => now()->subDay()]);

        $this->actingAs($owner)
            ->postJson(route('api.events.guests.resend', ['event' => $event->id, 'guest' => $guest->id]), [
                'channel' => 'sms',
            ])
            ->assertStatus(202)
            ->assertJson([
                'queued' => true,
                'queued_count' => 1,
                'kind' => 'invitation',
                'channel' => 'sms',
            ]);

        $delivery = InvitationDelivery::where('guest_id', $guest->id)->sole();
        $this->assertSame('invitation', $delivery->kind);
        $this->assertSame('sms', $delivery->channel);
        $this->assertSame('queued', $delivery->status);
        $this->assertNull($guest->fresh()->sent_at);

        Queue::assertPushed(SendInvitationJob::class, function (SendInvitationJob $job) use ($guest, $delivery) {
            return $job->guest->id === $guest->id
                && $job->channels === ['sms']
                && $job->deliveryId === $delivery->id;
        });
        Queue::assertPushed(SendInvitationJob::class, 1);
    }

    public function test_unavailable_channel_is_rejected_without_clearing_existing_state(): void
    {
        Queue::fake();
        $owner = $this->owner();
        $event = $this->event($owner);
        $guest = $this->guest($event, ['sent_at' => now()->subDay()]);
        $originalSentAt = $guest->sent_at;

        $this->actingAs($owner)
            ->postJson(route('api.events.guests.resend', ['event' => $event->id, 'guest' => $guest->id]), [
                'channel' => 'sms',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This channel is not enabled for guest messages in this environment.');

        Queue::assertNothingPushed();
        $this->assertSame(0, InvitationDelivery::count());
        $this->assertEquals($originalSentAt, $guest->fresh()->sent_at);
    }

    public function test_missing_recipient_destination_is_rejected_without_sensitive_values(): void
    {
        Queue::fake();
        $owner = $this->owner();
        $event = $this->event($owner);
        $guest = $this->guest($event, [
            'email' => null,
            'phone' => '+255799999999',
            'sent_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($owner)
            ->postJson(route('api.events.guests.resend', ['event' => $event->id, 'guest' => $guest->id]), [
                'channel' => 'mail',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This guest has no usable email address.');

        $this->assertStringNotContainsString('+255799999999', $response->getContent());
        Queue::assertNothingPushed();
        $this->assertSame(0, InvitationDelivery::count());
    }

    public function test_individual_reminder_uses_the_selected_channel_and_creates_a_reminder_record(): void
    {
        Queue::fake();
        $owner = $this->owner();
        $event = $this->event($owner);
        $guest = $this->guest($event);

        $this->actingAs($owner)
            ->postJson(route('api.events.guests.remind', ['event' => $event->id, 'guest' => $guest->id]), [
                'channel' => 'mail',
            ])
            ->assertStatus(202)
            ->assertJson([
                'queued' => true,
                'kind' => 'reminder',
                'channel' => 'mail',
            ]);

        $delivery = InvitationDelivery::where('guest_id', $guest->id)->sole();
        $this->assertSame('reminder', $delivery->kind);
        $this->assertSame('mail', $delivery->channel);
        $this->assertSame('queued', $delivery->status);

        Queue::assertPushed(SendReminderJob::class, function (SendReminderJob $job) use ($guest, $delivery) {
            return $job->guest->id === $guest->id
                && $job->channels === ['mail']
                && $job->deliveryId === $delivery->id;
        });
    }

    public function test_selected_invitation_batch_queues_only_requested_valid_guests(): void
    {
        Queue::fake();
        $owner = $this->owner();
        $event = $this->event($owner);
        $first = $this->guest($event, ['email' => 'first@example.test']);
        $second = $this->guest($event, ['email' => 'second@example.test']);
        $missingEmail = $this->guest($event, ['email' => null]);
        $foreign = $this->guest($this->event($this->owner()), ['email' => 'foreign@example.test']);

        $response = $this->actingAs($owner)
            ->postJson(route('api.events.invitations.send', $event), [
                'channel' => 'mail',
                'guest_ids' => [$first->id, $second->id, $missingEmail->id, $foreign->id],
            ])
            ->assertStatus(202)
            ->assertJson([
                'queued' => 2,
                'kind' => 'invitation',
                'channel' => 'mail',
            ]);

        $rejected = collect($response->json('rejected'))->keyBy('guest_id');
        $this->assertCount(2, $rejected);
        $this->assertArrayHasKey($missingEmail->id, $rejected->all());
        $this->assertArrayHasKey($foreign->id, $rejected->all());
        $this->assertSame(
            2,
            InvitationDelivery::whereIn('guest_id', [$first->id, $second->id])
                ->where('kind', 'invitation')
                ->where('channel', 'mail')
                ->where('status', 'queued')
                ->count()
        );
        $this->assertSame(0, InvitationDelivery::whereIn('guest_id', [$missingEmail->id, $foreign->id])->count());
        $this->assertStringNotContainsString($first->invite_token, $response->getContent());
        Queue::assertPushed(SendInvitationJob::class, 2);
    }

    public function test_selected_reminder_batch_preserves_existing_eligibility_rules(): void
    {
        Queue::fake();
        $owner = $this->owner();
        $event = $this->event($owner);

        $eligible = $this->guest($event, ['sent_at' => now()->subDays(3)]);
        $tooRecent = $this->guest($event, ['sent_at' => now()->subDay()]);
        $accepted = $this->guest($event, ['status' => 'accepted', 'sent_at' => now()->subDays(3)]);
        $reminded = $this->guest($event, ['sent_at' => now()->subDays(3), 'reminded_at' => now()->subDay()]);
        $notSelected = $this->guest($event, ['sent_at' => now()->subDays(3)]);

        $response = $this->actingAs($owner)
            ->postJson(route('api.events.guests.reminders', $event), [
                'channel' => 'mail',
                'guest_ids' => [$eligible->id, $tooRecent->id, $accepted->id, $reminded->id],
            ])
            ->assertStatus(202)
            ->assertJson(['queued' => 1, 'kind' => 'reminder', 'channel' => 'mail']);

        $this->assertCount(3, $response->json('rejected'));
        $this->assertSame(1, InvitationDelivery::where('guest_id', $eligible->id)->where('kind', 'reminder')->count());
        $this->assertSame(0, InvitationDelivery::where('guest_id', $notSelected->id)->count());
        Queue::assertPushed(SendReminderJob::class, 1);
    }

    public function test_selected_batch_requires_an_explicit_channel(): void
    {
        Queue::fake();
        $owner = $this->owner();
        $event = $this->event($owner);
        $guest = $this->guest($event);

        $this->actingAs($owner)
            ->postJson(route('api.events.invitations.send', $event), [
                'guest_ids' => [$guest->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('channel');

        Queue::assertNothingPushed();
        $this->assertSame(0, InvitationDelivery::count());
    }

    public function test_duplicate_individual_submission_is_debounced(): void
    {
        Queue::fake();
        $owner = $this->owner();
        $event = $this->event($owner);
        $guest = $this->guest($event);
        $url = route('api.events.guests.resend', ['event' => $event->id, 'guest' => $guest->id]);

        $this->actingAs($owner)->postJson($url, ['channel' => 'mail'])->assertStatus(202);

        $this->actingAs($owner)
            ->postJson($url, ['channel' => 'mail'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'A send for this guest was just requested. Please wait a moment before trying again.');

        Queue::assertPushed(SendInvitationJob::class, 1);
        $this->assertSame(1, InvitationDelivery::where('guest_id', $guest->id)->count());
    }

    public function test_delivery_lab_blocks_non_allowlisted_recipient_before_queueing(): void
    {
        Queue::fake();
        config([
            'delivery_lab.enabled' => true,
            'delivery_lab.recipients' => ['allowed@example.test'],
        ]);

        $owner = $this->owner();
        $event = $this->event($owner);
        $guest = $this->guest($event, ['email' => 'blocked@example.test']);

        $this->actingAs($owner)
            ->postJson(route('api.events.guests.resend', ['event' => $event->id, 'guest' => $guest->id]), [
                'channel' => 'mail',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The Delivery Lab restricts this recipient. Add them to the test allowlist before sending.');

        Queue::assertNothingPushed();
        $this->assertSame(0, InvitationDelivery::count());
    }

    public function test_selected_channel_job_records_provider_acceptance_not_delivery(): void
    {
        $guest = $this->guest($this->event($this->owner()));
        $delivery = InvitationDelivery::create([
            'guest_id' => $guest->id,
            'kind' => 'invitation',
            'channel' => 'mail',
            'status' => 'queued',
        ]);

        (new SendInvitationJob($guest, ['mail'], $delivery->id))->handle();

        $delivery->refresh();
        $this->assertSame('sent', $delivery->status);
        $this->assertNotNull($delivery->sent_at);
        $this->assertNull($delivery->delivered_at);
        $this->assertNotNull($guest->fresh()->sent_at);
        $this->assertSame(1, InvitationDelivery::count());
    }

    public function test_selected_sms_job_does_not_silently_send_another_channel(): void
    {
        config([
            'invitations.channels' => ['mail', 'sms'],
            'invitations.sms.endpoint' => 'https://sms.example.test/send',
        ]);
        Http::fake([
            'sms.example.test/*' => Http::response(['message_id' => 'sms-1'], 200),
        ]);

        $guest = $this->guest($this->event($this->owner()));
        $delivery = InvitationDelivery::create([
            'guest_id' => $guest->id,
            'kind' => 'invitation',
            'channel' => 'sms',
            'status' => 'queued',
        ]);

        (new SendInvitationJob($guest, ['sms'], $delivery->id))->handle();

        Http::assertSentCount(1);
        $this->assertSame('sent', $delivery->fresh()->status);
        $this->assertSame('sms-1', $delivery->fresh()->provider_message_id);
        $this->assertSame(1, InvitationDelivery::count());
        $this->assertSame(0, InvitationDelivery::where('channel', 'mail')->count());
        $this->assertNotNull($guest->fresh()->sent_at);
    }

    public function test_selected_reminder_job_transitions_queued_to_provider_accepted(): void
    {
        $guest = $this->guest($this->event($this->owner()), ['sent_at' => now()->subDays(3)]);
        $delivery = InvitationDelivery::create([
            'guest_id' => $guest->id,
            'kind' => 'reminder',
            'channel' => 'mail',
            'status' => 'queued',
        ]);

        (new SendReminderJob($guest, ['mail'], $delivery->id))->handle();

        $delivery->refresh();
        $this->assertSame('sent', $delivery->status);
        $this->assertNotNull($delivery->sent_at);
        $this->assertNull($delivery->delivered_at);
        $this->assertNotNull($guest->fresh()->reminded_at);
        $this->assertSame(1, InvitationDelivery::count());
    }

    public function test_retry_only_accepts_failed_deliveries_and_creates_a_traceable_retry(): void
    {
        Queue::fake();
        $owner = $this->owner();
        $event = $this->event($owner);
        $guest = $this->guest($event);

        $completed = InvitationDelivery::create([
            'guest_id' => $guest->id,
            'kind' => 'invitation',
            'channel' => 'mail',
            'status' => 'delivered',
            'delivered_at' => now(),
        ]);

        $this->actingAs($owner)
            ->postJson(route('api.events.deliveries.retry', ['event' => $event->id, 'delivery' => $completed->id]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only failed delivery attempts can be retried.');

        $failed = InvitationDelivery::create([
            'guest_id' => $guest->id,
            'kind' => 'invitation',
            'channel' => 'mail',
            'status' => 'failed',
            'failed_at' => now(),
            'error' => 'Sanitized failure.',
        ]);

        $this->actingAs($owner)
            ->postJson(route('api.events.deliveries.retry', ['event' => $event->id, 'delivery' => $failed->id]))
            ->assertStatus(202)
            ->assertJson(['queued' => true, 'queued_count' => 1]);

        $retry = InvitationDelivery::where('guest_id', $guest->id)->latest('id')->firstOrFail();
        $this->assertSame('queued', $retry->status);
        $this->assertSame($failed->id, $retry->meta['retry_of']);

        $this->actingAs($owner)
            ->postJson(route('api.events.deliveries.retry', ['event' => $event->id, 'delivery' => $failed->id]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'A retry for this delivery was just requested. Please wait a moment before trying again.');

        Queue::assertPushed(SendDeliveryJob::class, function (SendDeliveryJob $job) use ($failed, $retry, $guest) {
            return $job->guest->id === $guest->id
                && $job->kind === 'invitation'
                && $job->channel === 'mail'
                && $job->retryOf === $failed->id
                && $job->deliveryId === $retry->id;
        });
        Queue::assertPushed(SendDeliveryJob::class, 1);
    }

    public function test_preview_supports_reminders_and_guards_guest_scope(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $guest = $this->guest($event, ['name' => 'Preview Recipient']);
        $foreign = $this->guest($this->event($this->owner()));

        $this->actingAs($owner)
            ->getJson(route('api.events.invitations.preview', [
                'event' => $event->id,
                'kind' => 'reminder',
                'guest_id' => $guest->id,
            ]))
            ->assertOk()
            ->assertJsonPath('kind', 'reminder')
            ->assertJsonPath('recipient.name', 'Preview Recipient')
            ->assertJsonStructure([
                'channels' => ['mail' => ['subject', 'html', 'text'], 'sms' => ['message'], 'whatsapp' => ['message']],
                'available_channels' => ['channels', 'delivery_lab_restricted'],
            ]);

        $this->actingAs($owner)
            ->getJson(route('api.events.invitations.preview', [
                'event' => $event->id,
                'guest_id' => $foreign->id,
            ]))
            ->assertNotFound();
    }

    public function test_queue_dispatch_failure_marks_the_delivery_failed_and_returns_503(): void
    {
        config([
            'queue.default' => 'database',
            'queue.connections.database.table' => 'missing_communication_jobs_table',
        ]);

        $owner = $this->owner();
        $event = $this->event($owner);
        $guest = $this->guest($event);

        $this->actingAs($owner)
            ->postJson(route('api.events.guests.resend', ['event' => $event->id, 'guest' => $guest->id]), [
                'channel' => 'mail',
            ])
            ->assertStatus(503)
            ->assertJsonPath(
                'message',
                'The message could not be queued. No delivery was attempted; review Delivery History before retrying.',
            );

        $delivery = InvitationDelivery::where('guest_id', $guest->id)->sole();
        $this->assertSame('failed', $delivery->status);
        $this->assertNotNull($delivery->failed_at);
        $this->assertSame('The message could not be queued. No delivery was attempted.', $delivery->error);
        $this->assertStringNotContainsString($guest->invite_token, (string) json_encode($delivery->toArray()));
    }

    public function test_retry_queue_dispatch_failure_leaves_no_queued_orphan(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $guest = $this->guest($event);
        $failed = InvitationDelivery::create([
            'guest_id' => $guest->id,
            'kind' => 'invitation',
            'channel' => 'mail',
            'status' => 'failed',
            'failed_at' => now(),
            'error' => 'Original failure.',
        ]);

        config([
            'queue.default' => 'database',
            'queue.connections.database.table' => 'missing_retry_jobs_table',
        ]);

        $this->actingAs($owner)
            ->postJson(route('api.events.deliveries.retry', ['event' => $event->id, 'delivery' => $failed->id]))
            ->assertStatus(503);

        $retry = InvitationDelivery::where('guest_id', $guest->id)->latest('id')->firstOrFail();
        $this->assertNotSame($failed->id, $retry->id);
        $this->assertSame('failed', $retry->status);
        $this->assertSame('The message could not be queued. No delivery was attempted.', $retry->error);
        $this->assertSame($failed->id, $retry->meta['retry_of']);
        $this->assertSame(0, InvitationDelivery::where('guest_id', $guest->id)->where('status', 'queued')->count());
    }

    public function test_delivery_history_orders_guest_groups_by_latest_attempt_and_nests_newest_first(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $first = $this->guest($event);
        $second = $this->guest($event);

        $firstOlder = InvitationDelivery::create([
            'guest_id' => $first->id,
            'kind' => 'invitation',
            'channel' => 'mail',
            'status' => 'failed',
            'created_at' => Carbon::now()->subHours(4),
            'updated_at' => Carbon::now()->subHours(4),
        ]);
        $secondDelivery = InvitationDelivery::create([
            'guest_id' => $second->id,
            'kind' => 'invitation',
            'channel' => 'mail',
            'status' => 'sent',
            'created_at' => Carbon::now()->subHours(3),
            'updated_at' => Carbon::now()->subHours(3),
        ]);
        $firstNewer = InvitationDelivery::create([
            'guest_id' => $first->id,
            'kind' => 'reminder',
            'channel' => 'mail',
            'status' => 'queued',
            'created_at' => Carbon::now()->subHour(),
            'updated_at' => Carbon::now()->subHour(),
        ]);

        $response = $this->actingAs($owner)
            ->getJson(route('api.events.deliveries', ['event' => $event->id]))
            ->assertOk();

        $this->assertSame($first->id, $response->json('data.0.id'));
        $this->assertSame($second->id, $response->json('data.1.id'));
        $this->assertSame(
            [$firstNewer->id, $firstOlder->id],
            collect($response->json('data.0.deliveries'))->pluck('id')->all()
        );
        $this->assertSame($secondDelivery->id, $response->json('data.1.deliveries.0.id'));
        $this->assertArrayNotHasKey('latest_delivery_id', $response->json('data.0'));
    }
}
