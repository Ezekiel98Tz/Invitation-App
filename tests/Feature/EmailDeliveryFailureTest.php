<?php

namespace Tests\Feature;

use App\Jobs\SendDeliveryJob;
use App\Jobs\SendInvitationJob;
use App\Jobs\SendReminderJob;
use App\Models\Event;
use App\Models\Guest;
use App\Models\InvitationDelivery;
use App\Models\User;
use App\Notifications\DeliveryFailedNotification;
use Illuminate\Contracts\Mail\Factory as MailFactoryContract;
use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;
use Throwable;

/**
 * Email delivery failure handling.
 *
 * The mail transport is faked so a definitive transport exception can be
 * triggered without sending anything. Tests assert the recorded delivery state,
 * sanitization of failures, retry reuse, reminder parity, Delivery Lab
 * visibility and that SMS/WhatsApp behaviour is untouched.
 */
class EmailDeliveryFailureTest extends TestCase
{
    use RefreshDatabase;

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

    private function configureMailOnly(): void
    {
        config(['mail.default' => 'array', 'invitations.channels' => ['mail']]);
    }

    /**
     * Replace the mail factory with a controllable fake whose send() throws
     * while the flag is set. The same fake is reused for every send in the
     * test, so a retry can be made to succeed by clearing the flag.
     */
    private function installControllableMailer(Throwable $failure): void
    {
        $this->mailException = $failure;
        $this->mailFails = true;

        $mailer = Mockery::mock(MailerContract::class);
        $mailer->shouldReceive('send')->andReturnUsing(function () {
            if ($this->mailFails) {
                throw $this->mailException ?? new TransportException('mail failure');
            }

            return null;
        });

        $factory = Mockery::mock(MailFactoryContract::class);
        $factory->shouldReceive('mailer')->andReturn($mailer);

        $this->app->instance(MailFactoryContract::class, $factory);
    }

    private function mailDelivery(Guest $guest, string $kind = 'invitation'): InvitationDelivery
    {
        return InvitationDelivery::where('guest_id', $guest->id)
            ->where('kind', $kind)
            ->where('channel', 'mail')
            ->latest('id')
            ->firstOrFail();
    }

    private function runSwallowing(object $job): void
    {
        try {
            $job->handle();
        } catch (Throwable $e) {
            // The queue would retry; tests swallow to inspect the recorded state.
        }
    }

    private function asRetry(object $job, int $attempt): object
    {
        $queueJob = Mockery::mock(QueueJobContract::class);
        $queueJob->shouldReceive('attempts')->andReturn($attempt);

        $job->job = $queueJob;

        return $job;
    }

    public function test_successful_email_transport_records_accepted_state(): void
    {
        $this->configureMailOnly();
        $guest = $this->guest();

        (new SendInvitationJob($guest))->handle();

        $delivery = $this->mailDelivery($guest);

        // `sent` means the transport accepted the message for sending.
        $this->assertSame('sent', $delivery->status);
        $this->assertNotSame('delivered', $delivery->status);
        $this->assertNotNull($delivery->sent_at);
        $this->assertNull($delivery->delivered_at);
        $this->assertNull($delivery->error);
        $this->assertNull($delivery->provider_message_id);
        $this->assertTrue($delivery->meta['provider']['accepted']);
        $this->assertFalse($delivery->meta['provider']['has_reference']);

        // SMTP acceptance is never reported as delivery.
        $this->assertSame(0, InvitationDelivery::where('status', 'delivered')->count());
        $this->assertNotNull($guest->fresh()->sent_at);
    }

    public function test_immediate_transport_exception_records_a_failed_attempt(): void
    {
        $this->configureMailOnly();
        $this->installControllableMailer(new TransportException('Connection could not be established with host smtp.example.test'));
        $guest = $this->guest();

        $this->runSwallowing(new SendInvitationJob($guest));

        $delivery = $this->mailDelivery($guest);

        $this->assertSame('failed', $delivery->status);
        $this->assertNull($delivery->sent_at);
        $this->assertNotNull($delivery->failed_at);
        $this->assertSame('The email could not be sent by the configured mail transport.', $delivery->error);
        $this->assertFalse($delivery->meta['provider']['accepted']);
        $this->assertSame('mail_transport_error', $delivery->meta['provider']['reason']);
        $this->assertSame(TransportException::class, $delivery->meta['provider']['exception']);
        $this->assertSame('array', $delivery->meta['provider']['mailer']);

        // A definitively failed email is never marked as sent.
        $this->assertNull($guest->fresh()->sent_at);
    }

    public function test_failure_metadata_never_leaks_credentials_or_message_content(): void
    {
        $this->configureMailOnly();
        $this->installControllableMailer(new TransportException(
            'SMTP AUTH failed for admin@example.test with password=hunter2-super-secret; Authorization: Bearer abc123token'
        ));

        $guest = $this->guest(['name' => 'Ada']);
        $eventTitle = (string) $guest->event->title;

        $this->runSwallowing(new SendInvitationJob($guest));

        $delivery = $this->mailDelivery($guest);
        $haystack = (string) $delivery->error.' '.json_encode($delivery->meta);

        foreach ([
            'hunter2-super-secret',
            'admin@example.test',
            'Authorization',
            'Bearer',
            'abc123token',
            'SMTP AUTH',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $haystack);
        }

        // Neither the raw exception message nor the message body is stored.
        $this->assertStringNotContainsString('password', $haystack);
        $this->assertStringNotContainsString($eventTitle, $haystack);
    }

    public function test_a_retry_reuses_the_same_attempt_without_duplicating_records(): void
    {
        $this->configureMailOnly();
        $this->installControllableMailer(new TransportException('temporary failure'));
        $guest = $this->guest();

        // Attempt 1: transport fails, row recorded as failed.
        $this->runSwallowing(new SendInvitationJob($guest));
        $first = $this->mailDelivery($guest);
        $this->assertSame('failed', $first->status);

        // Attempt 2 (queue retry): transport succeeds.
        $this->mailFails = false;
        $this->runSwallowing($this->asRetry(new SendInvitationJob($guest), 2));

        $this->assertSame(1, InvitationDelivery::where('guest_id', $guest->id)->where('channel', 'mail')->count());

        $second = $this->mailDelivery($guest);
        $this->assertSame($first->id, $second->id);
        $this->assertSame('sent', $second->status);
        $this->assertNotNull($second->sent_at);
        $this->assertNull($second->error);
        $this->assertNotNull($guest->fresh()->sent_at);
    }

    public function test_exhausted_attempt_leaves_an_inspectable_failed_record_and_notifies_the_owner(): void
    {
        $this->configureMailOnly();
        $this->installControllableMailer(new TransportException('connection refused'));
        $guest = $this->guest();
        $owner = $guest->event->user;

        $job = new SendInvitationJob($guest);
        $this->runSwallowing($job);

        $delivery = $this->mailDelivery($guest);
        $this->assertSame('failed', $delivery->status);

        // The queue gives up and calls the failure hook.
        Notification::fake();
        $job->failed(new TransportException('raw SMTP detail password=secret'));

        Notification::assertSentTo($owner, DeliveryFailedNotification::class);

        // The hook must not clobber the sanitized error already recorded.
        $delivery->refresh();
        $this->assertSame('failed', $delivery->status);
        $this->assertSame('The email could not be sent by the configured mail transport.', $delivery->error);
    }

    public function test_reminder_email_uses_the_same_failure_handling(): void
    {
        $this->configureMailOnly();
        $this->installControllableMailer(new TransportException('connection refused'));
        $guest = $this->guest();

        $this->runSwallowing(new SendReminderJob($guest));

        $delivery = $this->mailDelivery($guest, 'reminder');

        $this->assertSame('failed', $delivery->status);
        $this->assertSame('mail_transport_error', $delivery->meta['provider']['reason']);
        $this->assertSame(TransportException::class, $delivery->meta['provider']['exception']);
        $this->assertNull($guest->fresh()->reminded_at);
    }

    public function test_successful_reminder_retry_transitions_the_same_attempt_to_sent(): void
    {
        $this->configureMailOnly();
        $this->installControllableMailer(new TransportException('temporary failure'));
        $guest = $this->guest();

        $this->runSwallowing(new SendReminderJob($guest));
        $first = $this->mailDelivery($guest, 'reminder');
        $this->assertSame('failed', $first->status);

        $this->mailFails = false;
        $this->runSwallowing($this->asRetry(new SendReminderJob($guest), 2));

        $this->assertSame(1, InvitationDelivery::where('guest_id', $guest->id)->where('kind', 'reminder')->count());

        $second = $this->mailDelivery($guest, 'reminder');
        $this->assertSame($first->id, $second->id);
        $this->assertSame('sent', $second->status);
        $this->assertNotNull($guest->fresh()->reminded_at);
    }

    public function test_explicit_retry_via_delivery_job_records_a_sanitized_mail_failure(): void
    {
        $this->configureMailOnly();
        $this->installControllableMailer(new TransportException('connection refused'));
        $guest = $this->guest();

        $earlier = InvitationDelivery::create([
            'guest_id' => $guest->id,
            'kind' => 'invitation',
            'channel' => 'mail',
            'status' => 'failed',
            'error' => 'The email could not be sent by the configured mail transport.',
            'failed_at' => now(),
        ]);

        $this->runSwallowing(new SendDeliveryJob($guest, 'invitation', 'mail', $earlier->id));

        $latest = InvitationDelivery::where('guest_id', $guest->id)->where('channel', 'mail')->latest('id')->firstOrFail();
        $this->assertNotSame($earlier->id, $latest->id);
        $this->assertSame($earlier->id, $latest->meta['retry_of']);
        $this->assertSame('failed', $latest->status);
        $this->assertSame('mail_transport_error', $latest->meta['provider']['reason']);
        $this->assertStringNotContainsString('connection refused', (string) $latest->error);
    }

    public function test_failed_email_is_visible_through_the_delivery_lab_api(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $owner->id]);
        $guest = Guest::factory()->create(['event_id' => $event->id, 'email' => 'guest@example.test']);

        InvitationDelivery::create([
            'guest_id' => $guest->id,
            'kind' => 'invitation',
            'channel' => 'mail',
            'status' => 'failed',
            'error' => 'The email could not be sent by the configured mail transport.',
            'failed_at' => now(),
            'meta' => [
                'provider' => [
                    'accepted' => false,
                    'reason' => 'mail_transport_error',
                    'exception' => TransportException::class,
                    'mailer' => 'smtp',
                ],
            ],
        ]);

        $response = $this->actingAs($owner)
            ->getJson(route('api.delivery-lab.index'))
            ->assertOk();

        $this->assertGreaterThanOrEqual(1, $response->json('counts.failed'));

        $row = collect($response->json('recent'))->firstWhere('channel', 'mail');

        $this->assertNotNull($row);
        $this->assertSame('failed', $row['status']);
        $this->assertSame('The email could not be sent by the configured mail transport.', $row['error']);
        $this->assertSame('mail_transport_error', $row['provider_status']);
        $this->assertFalse($row['simulated']);
    }

    public function test_delivery_lab_mail_test_send_is_sandboxed_without_external_requests(): void
    {
        Http::fake();

        config([
            'mail.default' => 'array',
            'invitations.channels' => ['mail'],
            'delivery_lab.enabled' => true,
            'delivery_lab.sandbox' => true,
            'delivery_lab.recipients' => ['guest@example.test'],
        ]);

        $owner = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $owner->id]);
        $guest = Guest::factory()->create(['event_id' => $event->id, 'email' => 'guest@example.test']);

        $this->actingAs($owner)
            ->postJson(route('api.delivery-lab.test-send', ['event' => $event->id, 'guest' => $guest->id]), [
                'kind' => 'invitation',
                'channel' => 'mail',
            ])
            ->assertOk()
            ->assertJsonPath('outcome', 'captured')
            ->assertJsonPath('delivery.mode', 'sandbox')
            ->assertJsonPath('delivery.simulated', true)
            ->assertJsonPath('delivery.status', 'sent');

        Http::assertNothingSent();
    }

    public function test_sms_delivery_behaviour_is_unchanged_when_email_is_also_present(): void
    {
        Http::fake(['sms.example.test/*' => Http::response(['message_id' => 'sms-ref'], 200)]);

        config([
            'mail.default' => 'array',
            'invitations.channels' => ['mail', 'sms'],
            'invitations.sms.endpoint' => 'https://sms.example.test/send',
        ]);

        $guest = $this->guest();

        (new SendInvitationJob($guest))->handle();

        $mail = $this->mailDelivery($guest);
        $sms = InvitationDelivery::where('guest_id', $guest->id)->where('channel', 'sms')->firstOrFail();

        $this->assertSame('sent', $mail->status);
        $this->assertSame('sent', $sms->status);
        $this->assertSame('sms-ref', $sms->provider_message_id);
        Http::assertSentCount(1);
    }

    public function test_sms_failure_does_not_regress_a_successful_email(): void
    {
        Http::fake(['sms.example.test/*' => Http::response('upstream error', 500)]);

        config([
            'mail.default' => 'array',
            'invitations.channels' => ['mail', 'sms'],
            'invitations.sms.endpoint' => 'https://sms.example.test/send',
        ]);

        $guest = $this->guest();

        $this->runSwallowing(new SendInvitationJob($guest));

        $mail = $this->mailDelivery($guest);
        $sms = InvitationDelivery::where('guest_id', $guest->id)->where('channel', 'sms')->latest('id')->firstOrFail();

        $this->assertSame('sent', $mail->status);
        $this->assertSame('failed', $sms->status);
    }
}
