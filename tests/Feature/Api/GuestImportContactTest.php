<?php

namespace Tests\Feature\Api;

use App\Jobs\ProcessGuestImportJob;
use App\Models\Contact;
use App\Models\Event;
use App\Models\Guest;
use App\Models\GuestImport;
use App\Models\User;
use App\Notifications\GuestImportCompletedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class GuestImportContactTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create();
    }

    private function event(User $owner): Event
    {
        return Event::factory()->create(['user_id' => $owner->id]);
    }

    private function import(Event $event, User $user, string $csv)
    {
        return $this->actingAs($user)
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('api.events.guests.import', [$event->id]), [
                'file' => UploadedFile::fake()->createWithContent('guests.csv', $csv),
                'mapping' => ['name' => 'name', 'email' => 'email', 'phone' => 'phone'],
            ]);
    }

    public function test_import_resolves_account_contacts(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);

        $this->import($event, $owner, "name,email,phone\nAlice,alice@example.test,+255700000001\nBob,bob@example.test,\n")
            ->assertStatus(202);

        $this->assertSame(2, Guest::count());
        $this->assertSame(2, Contact::count());
        $this->assertNotNull(Guest::where('email', 'alice@example.test')->first()->contact_id);
        $this->assertSame($owner->id, Guest::where('email', 'alice@example.test')->first()->contact->user_id);
    }

    public function test_repeated_rows_do_not_create_duplicate_contacts(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);

        // Same email twice in one event: the second row is skipped by the
        // existing event-level dedup, so neither a duplicate guest nor contact
        // is created.
        $this->import($event, $owner, "name,email,phone\nAlice,shared@example.test,\nAlice Two,shared@example.test,\n")
            ->assertStatus(202);

        $this->assertSame(1, Guest::count());
        $this->assertSame(1, Contact::count());
    }

    public function test_import_links_across_events_to_the_same_contact(): void
    {
        $owner = $this->owner();
        $eventA = $this->event($owner);
        $eventB = $this->event($owner);

        $this->import($eventA, $owner, "name,email,phone\nAlice,shared@example.test,\n")->assertStatus(202);
        $this->import($eventB, $owner, "name,email,phone\nAlice,shared@example.test,\n")->assertStatus(202);

        $this->assertSame(1, Contact::count());
        $contact = Contact::first();
        $this->assertSame($contact->id, Guest::where('event_id', $eventA->id)->first()->contact_id);
        $this->assertSame($contact->id, Guest::where('event_id', $eventB->id)->first()->contact_id);
    }

    public function test_conflict_row_is_reported_as_failed_without_creating_a_contact(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);

        Contact::factory()->create(['user_id' => $owner->id, 'email' => 'a@example.test', 'normalized_email' => 'a@example.test', 'phone' => null, 'normalized_phone' => null]);
        Contact::factory()->create(['user_id' => $owner->id, 'email' => null, 'normalized_email' => null, 'phone' => '+255700000001', 'normalized_phone' => '255700000001']);

        $this->import($event, $owner, "name,email,phone\nConflict,a@example.test,+255700000001\n")
            ->assertStatus(202);

        $this->assertSame(0, Guest::count());
        $this->assertSame(2, Contact::count());

        $this->actingAs($owner)
            ->getJson(route('api.events.imports.latest', [$event->id]))
            ->assertOk()
            ->assertJsonPath('import.status', 'completed')
            ->assertJsonPath('import.created_count', 0)
            ->assertJsonPath('import.skipped_count', 0)
            ->assertJsonPath('import.failed_count', 1);
    }

    public function test_import_distinguishes_conflicts_from_routine_skips(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);

        // Two contacts that split an email and a phone -> the combined row conflicts.
        Contact::factory()->create(['user_id' => $owner->id, 'email' => 'a@example.test', 'normalized_email' => 'a@example.test', 'phone' => null, 'normalized_phone' => null]);
        Contact::factory()->create(['user_id' => $owner->id, 'email' => null, 'normalized_email' => null, 'phone' => '+255700000001', 'normalized_phone' => '255700000001']);

        $csv = implode("\n", [
            'name,email,phone',
            'Conflict,a@example.test,+255700000001',   // failed: email/phone conflict
            ',nobody@example.test,',                     // skipped: blank name
            'Valid,valid@example.test,',                 // created
            'Valid Dup,valid@example.test,',             // skipped: duplicate within event
        ]);

        $this->import($event, $owner, $csv)->assertStatus(202);

        $this->assertSame(1, Guest::count());

        $this->actingAs($owner)
            ->getJson(route('api.events.imports.latest', [$event->id]))
            ->assertOk()
            ->assertJsonPath('import.created_count', 1)
            ->assertJsonPath('import.skipped_count', 2)
            ->assertJsonPath('import.failed_count', 1);
    }

    public function test_completed_notification_reports_the_conflict_count(): void
    {
        Notification::fake();

        $owner = $this->owner();
        $event = $this->event($owner);

        Contact::factory()->create(['user_id' => $owner->id, 'email' => 'a@example.test', 'normalized_email' => 'a@example.test', 'phone' => null, 'normalized_phone' => null]);
        Contact::factory()->create(['user_id' => $owner->id, 'email' => null, 'normalized_email' => null, 'phone' => '+255700000001', 'normalized_phone' => '255700000001']);

        $this->import($event, $owner, "name,email,phone\nConflict,a@example.test,+255700000001\n")
            ->assertStatus(202);

        Notification::assertSentTo(
            $owner,
            GuestImportCompletedNotification::class,
            fn (GuestImportCompletedNotification $notification) => $notification->failed === 1
        );
    }

    public function test_import_preserves_guest_operational_fields(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);

        $this->import($event, $owner, "name,email,phone\nAlice,alice@example.test,+255700000001\n")->assertStatus(202);

        $guest = Guest::first();
        $this->assertSame($event->id, $guest->event_id);
        $this->assertSame('Alice', $guest->name);
        $this->assertSame('alice@example.test', $guest->email);
        $this->assertSame('+255700000001', $guest->phone);
        $this->assertSame('pending', $guest->status);
        $this->assertSame(32, strlen((string) $guest->invite_token));
        $this->assertNotNull($guest->contact_id);
    }

    public function test_failed_import_leaves_no_orphan_contact(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'gimport-'.uniqid().'.csv';
        file_put_contents($path, "name,email,phone\nAlice,alice@example.test,\n");

        $import = GuestImport::create([
            'event_id' => $event->id,
            'user_id' => $owner->id,
            'original_filename' => 'guests.csv',
            'status' => 'queued',
        ]);

        Contact::creating(function () {
            throw new RuntimeException('boom');
        });

        try {
            (new ProcessGuestImportJob($import->id, $path, ['name' => 'name', 'email' => 'email', 'phone' => 'phone']))->handle();
            $this->fail('Expected the import job to throw.');
        } catch (Throwable $e) {
            // expected
        } finally {
            Contact::flushEventListeners();
        }

        $this->assertSame(0, Contact::count());
        $this->assertSame(0, Guest::count());
        $this->assertSame('failed', $import->fresh()->status);
    }
}
