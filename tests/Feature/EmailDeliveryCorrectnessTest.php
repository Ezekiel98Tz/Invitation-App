<?php

namespace Tests\Feature;

use App\Jobs\SendInvitationJob;
use App\Jobs\SendReminderJob;
use App\Models\Event;
use App\Models\Guest;
use App\Models\InvitationDelivery;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Support\MailDelivery;
use Illuminate\Contracts\Mail\Factory as MailFactoryContract;
use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Mockery;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;
use Throwable;

/**
 * Email delivery correctness pass.
 *
 * Covers missing/blank destinations, channel isolation, the meaning of
 * guest.sent_at, and the queued execution path (inline email, no nested
 * SendQueuedNotifications job). The mail transport is faked with a call counter
 * so "the transport was not called" can be asserted without sending anything.
 */
class EmailDeliveryCorrectnessTest extends TestCase
{
    use RefreshDatabase;

    private int $mailSendCalls = 0;

    private bool $mailFails = false;

    private ?Throwable $mailException = null;

    private function guest(array $attributes = []): Guest
    {
        $event = Event::factory()->create();

        return Guest::factory()->create(array_merge([
            'event_id' => $event->id,
            'name' => 'Ada',
            'email' => 'guest@example.test',
            'phone' => '+255700000001',
            'status' => 'pending',
            'sent_at' => null,
            'reminded_at' => null,
        ], $attributes));
    }

    private function installMailer(bool $fails = false, ?Throwable $exception = null): void
    {
        $this->mailSendCalls = 0;
        $this->mailFails = $fails;
        $this->mailException = $exception ?? new TransportException('mail failure');

        $mailer = Mockery::mock(MailerContract::class);
        $mailer->shouldReceive('send')->andReturnUsing(function () {
            $this->mailSendCalls++;

            if ($this->mailFails) {
                throw $this->mailException ?? new TransportException('mail failure');
            }

            return null;
        });

        $factory = Mockery::mock(MailFactoryContract::class);
        $factory->shouldReceive('mailer')->andReturn($mailer);

        $this->app->instance(MailFactoryContract::class, $factory);
    }

    private function mailRow(Guest $guest, string $kind = 'invitation'): ?InvitationDelivery
    {
        return InvitationDelivery::where('guest_id', $guest->id)
            ->where('kind', $kind)
            ->where('channel', 'mail')
            ->latest('id')
            ->first();
    }

    private function runSwallowing(object $job): void
    {
        try {
            $job->handle();
        } catch (Throwable $e) {
            // The queue would retry; tests swallow to inspect the recorded state.
        }
    }

    // ---------------------------------------------------------------------
    // 1. Missing / invalid email destinations
    // ---------------------------------------------------------------------

    public function test_invitation_without_an_email_does_not_create_a_delivery_or_reach_the_transport(): void
    {
        $this->installMailer();
        config(['mail.default' => 'array', 'invitations.channels' => ['mail']]);
        $guest = $this->guest(['email' => null]);

        (new SendInvitationJob($guest))->handle();

        $this->assertSame(0, InvitationDelivery::where('guest_id', $guest->id)->count());
        $this->assertSame(0, $this->mailSendCalls);
        $this->assertNull($guest->fresh()->sent_at);
    }

    public function test_blank_and_whitespace_emails_do_not_reach_the_transport(): void
    {
        $this->installMailer();
        config(['mail.default' => 'array', 'invitations.channels' => ['mail']]);

        foreach (['', '   '] as $email) {
            $guest = $this->guest(['email' => $email]);

            (new SendInvitationJob($guest))->handle();

            $this->assertSame(0, InvitationDelivery::where('guest_id', $guest->id)->count());
            $this->assertNull($guest->fresh()->sent_at);
        }

        $this->assertSame(0, $this->mailSendCalls);
    }

    public function test_reminder_without_an_email_does_not_create_a_delivery_or_reach_the_transport(): void
    {
        $this->installMailer();
        config(['mail.default' => 'array', 'invitations.channels' => ['mail']]);
        $guest = $this->guest(['email' => null, 'status' => 'pending', 'reminded_at' => null]);

        (new SendReminderJob($guest))->handle();

        $this->assertSame(0, InvitationDelivery::where('guest_id', $guest->id)->count());
        $this->assertSame(0, $this->mailSendCalls);
        $this->assertNull($guest->fresh()->reminded_at);
    }

    public function test_missing_email_leaves_other_channels_unaffected(): void
    {
        Http::fake(['sms.example.test/*' => Http::response(['message_id' => 'sms-ref'], 200)]);
        $this->installMailer();

        config([
            'mail.default' => 'array',
            'invitations.channels' => ['mail', 'sms'],
            'invitations.sms.endpoint' => 'https://sms.example.test/send',
        ]);

        $guest = $this->guest(['email' => null, 'phone' => '+255700000001']);

        (new SendInvitationJob($guest))->handle();

        $this->assertNull($this->mailRow($guest));
        $this->assertSame(0, $this->mailSendCalls);

        $sms = InvitationDelivery::where('guest_id', $guest->id)->where('channel', 'sms')->firstOrFail();
        $this->assertSame('sent', $sms->status);
        $this->assertNotNull($guest->fresh()->sent_at);
        Http::assertSentCount(1);
    }

    public function test_delivery_lab_test_send_rejects_a_missing_email_destination(): void
    {
        config([
            'mail.default' => 'array',
            'invitations.channels' => ['mail'],
            'delivery_lab.enabled' => true,
            'delivery_lab.recipients' => ['someone@example.test'],
        ]);

        $owner = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $owner->id]);
        $guest = Guest::factory()->create(['event_id' => $event->id, 'email' => null]);

        $this->actingAs($owner)
            ->postJson(route('api.delivery-lab.test-send', ['event' => $event->id, 'guest' => $guest->id]), [
                'kind' => 'invitation',
                'channel' => 'mail',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('channel');

        $this->assertSame(0, InvitationDelivery::where('guest_id', $guest->id)->count());
    }

    public function test_mail_helper_does_not_send_or_record_without_a_destination(): void
    {
        $this->installMailer();
        config(['mail.default' => 'array', 'invitations.channels' => ['mail']]);
        $guest = $this->guest(['email' => '   ']);

        $notification = new InvitationNotification($guest->event, $guest->invite_url_absolute);
        $delivery = InvitationDelivery::create([
            'guest_id' => $guest->id,
            'kind' => 'invitation',
            'channel' => 'mail',
            'status' => 'queued',
        ]);

        MailDelivery::send($guest, $notification, $delivery);

        $this->assertSame(0, $this->mailSendCalls);

        $delivery->refresh();
        $this->assertSame('queued', $delivery->status); // unattempted stays queued
        $this->assertNull($delivery->sent_at);
    }

    // ---------------------------------------------------------------------
    // 2. Channel isolation
    // ---------------------------------------------------------------------

    public function test_email_failure_does_not_mark_unattempted_channels_failed(): void
    {
        Http::fake();
        $this->installMailer(fails: true);

        config([
            'mail.default' => 'array',
            'invitations.channels' => ['mail', 'sms'],
            'invitations.sms.endpoint' => 'https://sms.example.test/send',
        ]);

        $guest = $this->guest();

        $this->runSwallowing(new SendInvitationJob($guest));

        $mail = $this->mailRow($guest);
        $sms = InvitationDelivery::where('guest_id', $guest->id)->where('channel', 'sms')->firstOrFail();

        $this->assertSame('failed', $mail->status);
        $this->assertSame('queued', $sms->status); // never attempted, never marked failed
        $this->assertNull($guest->fresh()->sent_at);

        Http::assertNothingSent();
    }

    public function test_retry_reuses_the_same_email_attempt_record(): void
    {
        $this->installMailer(fails: true);
        config(['mail.default' => 'array', 'invitations.channels' => ['mail']]);
        $guest = $this->guest();

        $this->runSwallowing(new SendInvitationJob($guest));
        $first = $this->mailRow($guest);
        $this->assertSame('failed', $first->status);

        // Queue retry (attempt 2): the same fake mailer now succeeds.
        $this->mailFails = false;
        $job = new SendInvitationJob($guest);
        $queueJob = Mockery::mock(QueueJobContract::class);
        $queueJob->shouldReceive('attempts')->andReturn(2);
        $job->job = $queueJob;

        $this->runSwallowing($job);

        $this->assertSame(1, InvitationDelivery::where('guest_id', $guest->id)->where('channel', 'mail')->count());

        $second = $this->mailRow($guest);
        $this->assertSame($first->id, $second->id);
        $this->assertSame('sent', $second->status);
        $this->assertNull($second->error);
    }

    // ---------------------------------------------------------------------
    // 3. guest.sent_at semantics
    // ---------------------------------------------------------------------

    public function test_email_transport_failure_cannot_mark_an_invitation_sent(): void
    {
        $this->installMailer(fails: true);
        config(['mail.default' => 'array', 'invitations.channels' => ['mail']]);
        $guest = $this->guest();

        $this->runSwallowing(new SendInvitationJob($guest));

        $this->assertNull($guest->fresh()->sent_at);
        $this->assertSame('failed', $this->mailRow($guest)->status);
    }

    public function test_successful_email_sets_guest_sent_at_without_touching_reminder_state(): void
    {
        $this->installMailer();
        config(['mail.default' => 'array', 'invitations.channels' => ['mail']]);
        $guest = $this->guest();

        (new SendInvitationJob($guest))->handle();

        $fresh = $guest->fresh();
        $this->assertNotNull($fresh->sent_at);   // invitation workflow ran
        $this->assertNull($fresh->reminded_at);  // reminder marker untouched

        $mail = $this->mailRow($guest);
        $this->assertSame('sent', $mail->status);
        $this->assertNotNull($mail->sent_at);    // delivery-level timestamp is separate
        $this->assertSame(1, $this->mailSendCalls);
    }

    public function test_reminder_does_not_overwrite_the_invitation_sent_timestamp(): void
    {
        $this->installMailer();
        config(['mail.default' => 'array', 'invitations.channels' => ['mail']]);
        $guest = $this->guest();
        $guest->forceFill(['sent_at' => now()->subDay()])->save();

        $original = $guest->fresh()->sent_at;

        (new SendReminderJob($guest->fresh()))->handle();

        $fresh = $guest->fresh();
        $this->assertNotNull($fresh->reminded_at);
        $this->assertNotNull($fresh->sent_at);
        $this->assertTrue($original->equalTo($fresh->sent_at)); // invitation timestamp preserved
    }

    // ---------------------------------------------------------------------
    // 4. Queue / retry architecture
    // ---------------------------------------------------------------------

    public function test_email_is_sent_inline_and_not_queued_as_a_nested_notification_job(): void
    {
        Bus::fake();
        config(['mail.default' => 'array', 'invitations.channels' => ['mail']]);
        $guest = $this->guest();

        $job = new SendInvitationJob($guest);
        $this->assertSame(3, $job->tries); // the job owns the retry lifecycle

        $job->handle();

        Bus::assertNotDispatched(SendQueuedNotifications::class);
        $this->assertSame('sent', $this->mailRow($guest)->status);
        $this->assertNotNull($guest->fresh()->sent_at);
    }

    public function test_sms_invitation_is_sent_inline_and_not_queued(): void
    {
        Bus::fake();
        Http::fake(['sms.example.test/*' => Http::response(['message_id' => 'sms-ref'], 200)]);
        config([
            'mail.default' => 'array',
            'invitations.channels' => ['sms'],
            'invitations.sms.endpoint' => 'https://sms.example.test/send',
        ]);

        $guest = $this->guest(['email' => null, 'phone' => '+255700000001']);

        (new SendInvitationJob($guest))->handle();

        // SMS is sent inline within the job so delivery rows reach a terminal
        // state and `sent_at` is set; it is no longer re-queued as a nested job.
        Bus::assertNotDispatched(SendQueuedNotifications::class);
        Http::assertSentCount(1);
        $this->assertNotNull($guest->fresh()->sent_at);
    }

    public function test_email_transport_failure_propagates_so_the_queue_can_retry(): void
    {
        $this->installMailer(fails: true);
        config(['mail.default' => 'array', 'invitations.channels' => ['mail']]);
        $guest = $this->guest();

        $thrown = null;

        try {
            (new SendInvitationJob($guest))->handle();
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(TransportException::class, $thrown);
        $this->assertSame('failed', $this->mailRow($guest)->status);
        $this->assertNull($guest->fresh()->sent_at);
    }

    // ---------------------------------------------------------------------
    // 5. No fabricated delivery confirmation
    // ---------------------------------------------------------------------

    public function test_no_provider_reference_or_delivered_status_is_fabricated_for_email(): void
    {
        $this->installMailer();
        config(['mail.default' => 'array', 'invitations.channels' => ['mail']]);
        $guest = $this->guest();

        (new SendInvitationJob($guest))->handle();

        $mail = $this->mailRow($guest);
        $this->assertSame('sent', $mail->status);
        $this->assertNotSame('delivered', $mail->status);
        $this->assertNull($mail->provider_message_id);
        $this->assertNull($mail->delivered_at);
        $this->assertFalse($mail->meta['provider']['has_reference']);
        $this->assertSame(0, InvitationDelivery::where('status', 'delivered')->count());
    }
}
