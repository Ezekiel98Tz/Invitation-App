<?php

namespace Tests\Feature;

use App\Jobs\SendEntryCredentialJob;
use App\Jobs\SendInvitationJob;
use App\Jobs\SendReminderJob;
use App\Models\Event;
use App\Models\Guest;
use App\Models\InvitationDelivery;
use App\Services\CredentialIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Each delivery job takes a per-guest cache lock so concurrent duplicate
 * dispatches cannot send twice. When the lock is already held, the job must be
 * a safe no-op (no send, no delivery row, no `sent_at`/`reminded_at`).
 *
 * NOTE: the test suite uses the array cache store, which is per-process, so this
 * proves the lock is *honoured*, not multi-host coordination. Production uses
 * the database cache store (shared `cache_locks` table) — see README.
 */
class DeliveryLockTest extends TestCase
{
    use RefreshDatabase;

    private function guest(array $attributes = []): Guest
    {
        $event = Event::factory()->create();

        return Guest::factory()->create(array_merge([
            'event_id' => $event->id,
            'email' => 'guest@example.test',
            'phone' => null,
            'status' => 'pending',
            'sent_at' => null,
            'reminded_at' => null,
        ], $attributes));
    }

    public function test_invitation_job_is_a_noop_while_the_lock_is_held(): void
    {
        config(['mail.default' => 'array', 'invitations.channels' => ['mail']]);
        $guest = $this->guest();

        $lock = Cache::lock('invitation:guest:'.$guest->id, 60);
        $this->assertTrue($lock->get());

        (new SendInvitationJob($guest))->handle();

        $this->assertSame(0, InvitationDelivery::where('guest_id', $guest->id)->count());
        $this->assertNull($guest->fresh()->sent_at);

        $lock->release();
        (new SendInvitationJob($guest))->handle();

        $this->assertSame(1, InvitationDelivery::where('guest_id', $guest->id)->where('kind', 'invitation')->count());
        $this->assertNotNull($guest->fresh()->sent_at);
    }

    public function test_reminder_job_is_a_noop_while_the_lock_is_held(): void
    {
        config(['mail.default' => 'array', 'invitations.channels' => ['mail']]);
        $guest = $this->guest();

        $lock = Cache::lock('reminder:guest:'.$guest->id, 60);
        $this->assertTrue($lock->get());

        (new SendReminderJob($guest))->handle();

        $this->assertSame(0, InvitationDelivery::where('guest_id', $guest->id)->count());
        $this->assertNull($guest->fresh()->reminded_at);

        $lock->release();
        (new SendReminderJob($guest))->handle();

        $this->assertSame(1, InvitationDelivery::where('guest_id', $guest->id)->where('kind', 'reminder')->count());
        $this->assertNotNull($guest->fresh()->reminded_at);
    }

    public function test_credential_job_is_a_noop_while_the_lock_is_held(): void
    {
        config(['mail.default' => 'array', 'invitations.channels' => ['mail']]);
        $guest = $this->guest(['status' => 'accepted']);
        app(CredentialIssuer::class)->activate($guest);

        $lock = Cache::lock('credential:guest:'.$guest->id, 60);
        $this->assertTrue($lock->get());

        (new SendEntryCredentialJob($guest))->handle();

        $this->assertSame(0, InvitationDelivery::where('guest_id', $guest->id)->count());

        $lock->release();
        (new SendEntryCredentialJob($guest))->handle();

        $this->assertSame(1, InvitationDelivery::where('guest_id', $guest->id)->where('kind', 'credential')->count());
    }
}
