<?php

namespace Tests\Feature;

use App\Jobs\SendDeliveryJob;
use App\Jobs\SendInvitationJob;
use App\Models\ActivityLog;
use App\Models\Event;
use App\Models\Guest;
use App\Models\InvitationDelivery;
use App\Models\User;
use App\Notifications\DeliveryFailedNotification;
use App\Notifications\RsvpReceivedNotification;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WorkflowCompletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_rsvp_sends_optional_admin_notification_and_renders_confirmation(): void
    {
        Notification::fake();

        $event = Event::factory()->create();
        $guest = Guest::factory()->create(['event_id' => $event->id]);

        $this->post(route('invites.store', ['token' => $guest->invite_token]), [
            'status' => 'declined',
        ])->assertOk()->assertViewIs('invite.confirmed');

        Notification::assertSentTo($event->user, RsvpReceivedNotification::class);
    }

    public function test_send_invitation_job_creates_delivery_rows_and_webhook_marks_delivered(): void
    {
        config()->set('mail.default', 'array');
        config()->set('invitations.channels', ['mail', 'sms']);
        config()->set('invitations.sms.endpoint', 'https://sms.example.test/send');
        config()->set('invitations.webhook_secret', 'secret');

        Http::fake([
            'sms.example.test/*' => Http::response(['message_id' => 'sms-123'], 200),
        ]);

        $guest = Guest::factory()->create(['sent_at' => null]);

        (new SendInvitationJob($guest))->handle();

        $guest->refresh();
        $this->assertNotNull($guest->sent_at);

        $this->assertDatabaseCount('invitation_deliveries', 2);

        $smsDelivery = InvitationDelivery::query()
            ->where('guest_id', $guest->id)
            ->where('kind', 'invitation')
            ->where('channel', 'sms')
            ->first();

        $this->assertNotNull($smsDelivery);
        $this->assertSame('sent', $smsDelivery->status);
        $this->assertSame('sms-123', $smsDelivery->provider_message_id);

        $this->postJson(route('webhooks.delivery.store', ['channel' => 'sms']), [
            'provider_message_id' => 'sms-123',
            'status' => 'delivered',
        ], [
            'X-Webhook-Secret' => 'secret',
        ])->assertOk();

        $smsDelivery->refresh();
        $this->assertSame('delivered', $smsDelivery->status);
        $this->assertNotNull($smsDelivery->delivered_at);
    }

    public function test_admin_can_preview_invitation_and_queue_bulk_send(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $user->id]);
        Guest::factory()->count(2)->create(['event_id' => $event->id, 'sent_at' => null]);

        $this->actingAs($user)
            ->getJson(route('admin.events.invitations.preview', ['event' => $event->id]))
            ->assertOk()
            ->assertJsonStructure(['event_id', 'channels' => ['mail', 'sms', 'whatsapp']]);

        $this->actingAs($user)
            ->postJson(route('admin.events.invitations.send', ['event' => $event->id]))
            ->assertStatus(202)
            ->assertJsonStructure(['queued']);
    }

    public function test_admin_cannot_access_other_admin_event(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($other)
            ->getJson(route('admin.events.invitations.preview', ['event' => $event->id]))
            ->assertForbidden();
    }

    public function test_admin_send_is_rate_limited(): void
    {
        config()->set('cache.default', 'array');
        config()->set('invitations.rate_limits.admin_send_per_minute', 1);

        $user = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $user->id]);
        Guest::factory()->create(['event_id' => $event->id, 'sent_at' => null]);

        $this->actingAs($user)
            ->postJson(route('admin.events.invitations.send', ['event' => $event->id]))
            ->assertStatus(202);

        $this->actingAs($user)
            ->postJson(route('admin.events.invitations.send', ['event' => $event->id]))
            ->assertStatus(429);
    }

    public function test_admin_can_retry_failed_delivery(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $user->id]);
        $guest = Guest::factory()->create(['event_id' => $event->id]);

        $delivery = InvitationDelivery::create([
            'guest_id' => $guest->id,
            'kind' => 'invitation',
            'channel' => 'sms',
            'status' => 'failed',
            'error' => 'fail',
            'failed_at' => now(),
        ]);

        $this->actingAs($user)
            ->postJson(route('admin.events.deliveries.retry', ['event' => $event->id, 'delivery' => $delivery->id]))
            ->assertStatus(202);

        Bus::assertDispatched(SendDeliveryJob::class);
    }

    public function test_delivery_failed_notification_is_sent_on_job_failed_callback(): void
    {
        Notification::fake();

        $guest = Guest::factory()->create();

        InvitationDelivery::create([
            'guest_id' => $guest->id,
            'kind' => 'invitation',
            'channel' => 'mail',
            'status' => 'queued',
        ]);

        (new SendInvitationJob($guest))->failed(new Exception('boom'));

        Notification::assertSentTo($guest->event->user, DeliveryFailedNotification::class);
    }

    public function test_audit_logs_record_sensitive_actions(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('events.store'), [
                'title' => 'Test',
                'description' => null,
                'event_start' => now()->addDay()->toDateTimeString(),
                'timezone' => 'Africa/Dar_es_Salaam',
                'venue' => 'Venue',
                'capacity' => 10,
                'settings' => [],
            ])->assertStatus(201);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'action' => 'event.create',
        ]);

        $this->actingAs($user)
            ->getJson(route('admin.audit-logs.index'))
            ->assertOk();
    }

    public function test_failed_deliveries_list_and_bulk_retry(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $user->id]);
        $guest = Guest::factory()->create(['event_id' => $event->id]);

        InvitationDelivery::create([
            'guest_id' => $guest->id,
            'kind' => 'invitation',
            'channel' => 'sms',
            'status' => 'failed',
            'error' => 'fail',
            'failed_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson(route('admin.events.deliveries.failed', ['event' => $event->id]))
            ->assertOk()
            ->assertJsonStructure(['data']);

        $this->actingAs($user)
            ->postJson(route('admin.events.deliveries.retryFailed', ['event' => $event->id]))
            ->assertStatus(202)
            ->assertJsonStructure(['queued']);

        Bus::assertDispatched(SendDeliveryJob::class);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'action' => 'deliveries.retry_failed',
        ]);
    }
}
