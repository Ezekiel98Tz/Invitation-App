<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\Celebration;
use App\Models\Contact;
use App\Models\Event;
use App\Models\Guest;
use App\Models\InvitationCredential;
use App\Models\InvitationDelivery;
use App\Models\Rsvp;
use App\Models\User;
use App\Services\CredentialIssuer;
use App\Support\BrowserTestEnvironment;
use App\Support\ContactNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class SeedBrowserTestFixtures extends Command
{
    protected $signature = 'browser-test:seed';

    protected $description = 'Create deterministic fixtures in the isolated browser-test database';

    public function handle(): int
    {
        try {
            BrowserTestEnvironment::verify();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $password = (string) config('browser_testing.user_password');
        $owner = $this->fixtureUser(
            (string) config('browser_testing.user_email'),
            (string) config('browser_testing.user_name'),
            $password,
            'admin',
        );

        // This account is intentionally data-free and is only used for read-only
        // empty-state browser checks. No browser test creates data under it.
        $this->fixtureUser(
            (string) config('browser_testing.empty_user_email'),
            (string) config('browser_testing.empty_user_name'),
            $password,
            'admin',
        );

        $this->fixtureUser(
            (string) config('browser_testing.staff_user_email'),
            (string) config('browser_testing.staff_user_name'),
            $password,
            'staff',
            $owner->id,
        );

        $celebration = Celebration::query()->firstOrNew([
            'user_id' => $owner->id,
            'name' => (string) config('browser_testing.celebration_name'),
        ]);
        $celebration->forceFill([
            'user_id' => $owner->id,
            'name' => (string) config('browser_testing.celebration_name'),
            'description' => 'A fixture grouping with independently managed event records.',
        ])->save();

        // The primary event sits inside the entry-credential window so the
        // browser suite can exercise the real check-in flow without time travel.
        $event = $this->fixtureEvent(
            $owner,
            (string) config('browser_testing.event_title'),
            $celebration,
            now()->addMinutes(30),
            'Browser Test Hall',
        );

        $standalone = $this->fixtureEvent(
            $owner,
            (string) config('browser_testing.standalone_event_title'),
            null,
            now()->addDays(35)->setTime(16, 30),
            'Browser Test Garden',
            false,
        );

        $pastEvent = $this->fixtureEvent(
            $owner,
            (string) config('browser_testing.past_event_title'),
            null,
            now()->subDays(2),
            'Browser Test Archive Hall',
        );

        $upcomingEvent = $this->fixtureEvent(
            $owner,
            (string) config('browser_testing.upcoming_event_title'),
            null,
            now()->addDays(2),
            'Browser Test Future Hall',
        );

        $fixtureEvents = [$event, $standalone, $pastEvent, $upcomingEvent];

        // Phase 5 browser actions add queued delivery rows, credentials,
        // attendance and jobs. Reset only the fixture-owned event records so
        // repeated runs stay deterministic.
        $fixtureGuestIds = Guest::query()
            ->whereIn('event_id', array_map(static fn (Event $fixtureEvent): int => $fixtureEvent->id, $fixtureEvents))
            ->pluck('id');
        Attendance::query()->whereIn('guest_id', $fixtureGuestIds)->delete();
        InvitationCredential::query()->whereIn('guest_id', $fixtureGuestIds)->delete();
        InvitationDelivery::query()->whereIn('guest_id', $fixtureGuestIds)->delete();
        DB::table('jobs')->delete();
        DB::table('failed_jobs')->delete();

        $guestFixtures = [
            [
                'name' => 'Avery Browser',
                'email' => 'avery.browser@example.test',
                'phone' => '+255000000001',
                'status' => 'accepted',
                'sent_at' => now()->subDays(4),
                'delivery' => ['kind' => 'invitation', 'status' => 'delivered', 'age_days' => 4],
                'attending_count' => 2,
            ],
            [
                'name' => 'Briar Example',
                'email' => 'briar.example@example.test',
                'phone' => '+255000000002',
                'status' => 'pending',
                'sent_at' => null,
                'delivery' => ['kind' => 'invitation', 'status' => 'queued', 'age_days' => 0],
            ],
            [
                'name' => 'Casey Example',
                'email' => 'casey.example@example.test',
                'phone' => '+255000000003',
                'status' => 'pending',
                'sent_at' => now()->subDays(3),
                'delivery' => ['kind' => 'invitation', 'status' => 'sent', 'age_days' => 3],
            ],
            [
                'name' => 'Devon Example',
                'email' => 'devon.example@example.test',
                'phone' => '+255000000004',
                'status' => 'pending',
                'sent_at' => now()->subDays(6),
                'delivery' => ['kind' => 'reminder', 'status' => 'failed', 'age_days' => 1],
            ],
            [
                'name' => 'Ellis Example',
                'email' => 'ellis.example@example.test',
                'phone' => '+255000000005',
                'status' => 'declined',
                'sent_at' => now()->subDays(5),
                'delivery' => ['kind' => 'invitation', 'status' => 'delivered', 'age_days' => 5],
            ],
            [
                'name' => 'Finley Example',
                'email' => 'finley.example@example.test',
                'phone' => '+255000000006',
                'status' => 'pending',
                'sent_at' => now()->subDays(4),
                'delivery' => ['kind' => 'invitation', 'status' => 'delivered', 'age_days' => 4],
            ],
            [
                'name' => 'Harper Example',
                'email' => 'harper.example@example.test',
                'phone' => '+255000000007',
                'status' => 'pending',
                'sent_at' => null,
                'delivery' => null,
            ],
            [
                'name' => 'Pass Revoke Guest',
                'email' => 'pass.revoke@example.test',
                'phone' => '+255000000008',
                'status' => 'accepted',
                'sent_at' => now()->subDays(2),
                'delivery' => ['kind' => 'invitation', 'status' => 'delivered', 'age_days' => 2],
                'attending_count' => 1,
            ],
        ];

        $averyContact = null;
        foreach ($guestFixtures as $fixture) {
            $contact = $this->fixtureContact(
                $owner,
                $fixture['name'],
                $fixture['email'],
                $fixture['phone'],
            );

            if ($fixture['email'] === 'avery.browser@example.test') {
                $averyContact = $contact;
            }

            $guest = $this->fixtureGuest($event, $contact, $fixture);

            if ($fixture['delivery'] !== null) {
                $this->fixtureDelivery($guest, $fixture['delivery']);
            }
        }

        if ($averyContact instanceof Contact) {
            // The same account contact may link to guest records in more than one
            // event. Event-specific RSVP and delivery records remain separate.
            $this->fixtureGuest($standalone, $averyContact, [
                'name' => 'Avery Browser',
                'email' => 'avery.browser@example.test',
                'phone' => '+255000000001',
                'status' => 'pending',
                'sent_at' => null,
            ]);
        }

        $issuer = app(CredentialIssuer::class);

        foreach (['avery.browser@example.test', 'pass.revoke@example.test'] as $acceptedEmail) {
            $acceptedGuest = Guest::query()
                ->where('event_id', $event->id)
                ->where('email', $acceptedEmail)
                ->first();

            if ($acceptedGuest) {
                $issuer->activate($acceptedGuest);
            }
        }

        $this->fixturePassGuest(
            $pastEvent,
            'Past Pass Guest',
            'past.pass@example.test',
            '+255000000009',
            $issuer,
        );

        $this->fixturePassGuest(
            $upcomingEvent,
            'Upcoming Pass Guest',
            'upcoming.pass@example.test',
            '+255000000010',
            $issuer,
        );

        $this->info('Browser-test fixtures ready in '.BrowserTestEnvironment::DATABASE.'.');
        $this->line('Organizer: '.(string) config('browser_testing.user_email'));
        $this->line('Event: '.(string) config('browser_testing.event_title').' (ID '.$event->id.')');
        $this->line('Celebration: '.(string) config('browser_testing.celebration_name').' (ID '.$celebration->id.')');

        return self::SUCCESS;
    }

    private function fixtureUser(string $email, string $name, string $password, string $role, ?int $ownerId = null): User
    {
        $user = User::query()->firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'email_verified_at' => now(),
            'password' => Hash::make($password),
            'role' => $role,
            'owner_id' => $ownerId,
        ])->save();

        return $user;
    }

    private function fixtureEvent(
        User $owner,
        string $title,
        ?Celebration $celebration,
        \Illuminate\Support\Carbon $startsAt,
        string $venue,
        bool $operationalCapabilities = true,
    ): Event {
        $event = Event::withTrashed()
            ->where('user_id', $owner->id)
            ->where('title', $title)
            ->first() ?? new Event();

        $event->forceFill([
            'user_id' => $owner->id,
            'celebration_id' => $celebration?->id,
            'title' => $title,
            'description' => 'Deterministic event created for the isolated Playwright suite.',
            'event_start' => $startsAt,
            'timezone' => 'UTC',
            'venue' => $venue,
            'capacity' => null,
            'settings' => [
                'event_type' => 'corporate',
                'allow_plus_ones' => false,
                'custom_questions' => [],
                'enable_waitlist' => false,
                'capabilities' => [
                    'check_in' => $operationalCapabilities,
                    'contributions' => $operationalCapabilities,
                ],
            ],
            'deleted_at' => null,
        ])->save();

        return $event;
    }

    private function fixturePassGuest(
        Event $event,
        string $name,
        string $email,
        string $phone,
        CredentialIssuer $issuer,
    ): Guest {
        $guest = Guest::query()->firstOrNew([
            'event_id' => $event->id,
            'email' => $email,
        ]);
        $guest->forceFill([
            'event_id' => $event->id,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'status' => 'accepted',
            'sent_at' => now()->subDay(),
            'reminded_at' => null,
        ])->save();

        $issuer->activate($guest);

        return $guest;
    }

    private function fixtureContact(User $owner, string $name, string $email, string $phone): Contact
    {
        $normalizedEmail = ContactNormalizer::email($email);
        $contact = Contact::query()
            ->where('user_id', $owner->id)
            ->where('normalized_email', $normalizedEmail)
            ->first() ?? new Contact();

        $contact->forceFill([
            'user_id' => $owner->id,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'normalized_email' => $normalizedEmail,
            'normalized_phone' => ContactNormalizer::phone($phone),
            'notes' => $email === 'avery.browser@example.test'
                ? 'Browser-test contact linked to guest records in separate events.'
                : null,
        ])->save();

        return $contact;
    }

    /** @param array{name: string, email: string, phone: string, status: string, sent_at: mixed, attending_count?: int} $fixture */
    private function fixtureGuest(Event $event, Contact $contact, array $fixture): Guest
    {
        $guest = Guest::query()->firstOrNew([
            'event_id' => $event->id,
            'email' => $fixture['email'],
        ]);
        $guest->forceFill([
            'event_id' => $event->id,
            'contact_id' => $contact->id,
            'name' => $fixture['name'],
            'email' => $fixture['email'],
            'phone' => $fixture['phone'],
            'status' => $fixture['status'],
            'sent_at' => $fixture['sent_at'],
            'reminded_at' => null,
        ])->save();

        if (isset($fixture['attending_count'])) {
            Rsvp::query()->updateOrCreate(
                ['guest_id' => $guest->id],
                ['attending_count' => $fixture['attending_count'], 'answers' => ['dietary' => 'Vegetarian']],
            );
        } else {
            Rsvp::query()->where('guest_id', $guest->id)->delete();
        }

        return $guest;
    }

    /** @param array{kind: string, status: string, age_days: int} $fixture */
    private function fixtureDelivery(Guest $guest, array $fixture): void
    {
        $delivery = InvitationDelivery::query()
            ->where('guest_id', $guest->id)
            ->where('kind', $fixture['kind'])
            ->where('channel', 'mail')
            ->where('status', $fixture['status'])
            ->first() ?? new InvitationDelivery();

        $attemptTime = now()->subDays($fixture['age_days']);
        $delivery->forceFill([
            'guest_id' => $guest->id,
            'kind' => $fixture['kind'],
            'channel' => 'mail',
            'status' => $fixture['status'],
            'provider_message_id' => null,
            'error' => $fixture['status'] === 'failed'
                ? 'The delivery could not be sent.'
                : null,
            'meta' => ['browser_test_fixture' => true],
            'sent_at' => in_array($fixture['status'], ['sent', 'delivered'], true) ? $attemptTime : null,
            'delivered_at' => $fixture['status'] === 'delivered' ? $attemptTime->copy()->addMinutes(2) : null,
            'failed_at' => $fixture['status'] === 'failed' ? $attemptTime : null,
        ])->save();
    }
}
