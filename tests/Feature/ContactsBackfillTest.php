<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\Event;
use App\Models\Guest;
use App\Models\GuestImport;
use App\Models\InvitationDelivery;
use App\Models\Rsvp;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ContactsBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
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
        return Guest::factory()->create(array_merge(['event_id' => $event->id], $attributes));
    }

    public function test_dry_run_creates_no_contacts_and_changes_no_links(): void
    {
        $owner = User::factory()->create();
        $event = $this->event($owner);
        $this->guest($event, ['email' => 'a@example.test']);
        $this->guest($event, ['email' => 'b@example.test']);

        $this->artisan('contacts:backfill')->assertExitCode(Command::SUCCESS);

        $this->assertSame(0, Contact::count());
        $this->assertSame(0, Guest::whereNotNull('contact_id')->count());
    }

    public function test_apply_creates_contacts_and_links_eligible_guests(): void
    {
        $owner = User::factory()->create();
        $eventA = $this->event($owner);
        $eventB = $this->event($owner);
        $guestA = $this->guest($eventA, ['email' => 'a@example.test']);
        $guestB = $this->guest($eventB, ['email' => 'b@example.test']);

        $this->artisan('contacts:backfill', ['--apply' => true])->assertExitCode(Command::SUCCESS);

        $this->assertSame(2, Contact::count());

        $contactA = $guestA->fresh()->contact;
        $contactB = $guestB->fresh()->contact;

        $this->assertNotNull($contactA);
        $this->assertNotNull($contactB);
        $this->assertNotSame($contactA->id, $contactB->id);
        $this->assertSame($owner->id, $contactA->user_id);
        $this->assertSame($owner->id, $contactB->user_id);
    }

    public function test_repeated_apply_is_idempotent(): void
    {
        $owner = User::factory()->create();
        $event = $this->event($owner);
        $this->guest($event, ['email' => 'same@example.test']);

        $this->artisan('contacts:backfill', ['--apply' => true])->assertExitCode(Command::SUCCESS);
        $contacts = Contact::count();
        $links = Guest::whereNotNull('contact_id')->count();

        $this->artisan('contacts:backfill', ['--apply' => true])->assertExitCode(Command::SUCCESS);

        $this->assertSame($contacts, Contact::count());
        $this->assertSame($links, Guest::whereNotNull('contact_id')->count());
        $this->assertSame(1, $contacts);
    }

    public function test_email_matching_is_normalized_and_trimmed(): void
    {
        $owner = User::factory()->create();
        $eventA = $this->event($owner);
        $eventB = $this->event($owner);
        $guestA = $this->guest($eventA, ['email' => '  Shared@Example.TEST  ']);
        $guestB = $this->guest($eventB, ['email' => 'shared@example.test']);

        $this->artisan('contacts:backfill', ['--apply' => true])->assertExitCode(Command::SUCCESS);

        $this->assertSame(1, Contact::count());
        $contact = Contact::first();
        $this->assertSame('shared@example.test', $contact->normalized_email);
        $this->assertSame($contact->id, $guestA->fresh()->contact_id);
        $this->assertSame($contact->id, $guestB->fresh()->contact_id);
    }

    public function test_phone_matching_uses_digits_only_and_ignores_unusable_numbers(): void
    {
        $owner = User::factory()->create();
        $eventA = $this->event($owner);
        $eventB = $this->event($owner);
        $eventC = $this->event($owner);

        $guestA = $this->guest($eventA, ['email' => null, 'phone' => '+255 712-345-678']);
        $guestB = $this->guest($eventB, ['email' => null, 'phone' => '255712345678']);
        $guestC = $this->guest($eventC, ['email' => null, 'phone' => '12']);

        $this->artisan('contacts:backfill', ['--apply' => true])->assertExitCode(Command::SUCCESS);

        // The two formatted forms collapse to one contact; the unusable value
        // yields its own contact with no normalized phone.
        $this->assertSame(2, Contact::count());
        $this->assertSame($guestA->fresh()->contact_id, $guestB->fresh()->contact_id);
        $this->assertNotNull($guestA->fresh()->contact_id);
        $this->assertSame('255712345678', Contact::find($guestA->fresh()->contact_id)->normalized_phone);

        $guestC->refresh();
        $this->assertNotNull($guestC->contact_id);
        $this->assertNotSame($guestA->contact_id, $guestC->contact_id);
        $this->assertNull(Contact::find($guestC->contact_id)->normalized_phone);
    }

    public function test_matching_is_scoped_to_the_owning_account(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $guestA = $this->guest($this->event($ownerA), ['email' => 'shared@example.test']);
        $guestB = $this->guest($this->event($ownerB), ['email' => 'shared@example.test']);

        $this->artisan('contacts:backfill', ['--apply' => true])->assertExitCode(Command::SUCCESS);

        $this->assertSame(2, Contact::count());
        $this->assertNotSame($guestA->fresh()->contact_id, $guestB->fresh()->contact_id);
        $this->assertSame($ownerA->id, $guestA->fresh()->contact->user_id);
        $this->assertSame($ownerB->id, $guestB->fresh()->contact->user_id);
    }

    public function test_name_only_matching_never_occurs(): void
    {
        $owner = User::factory()->create();
        $eventA = $this->event($owner);
        $eventB = $this->event($owner);
        $guestA = $this->guest($eventA, ['name' => 'John Doe', 'email' => 'john.a@example.test']);
        $guestB = $this->guest($eventB, ['name' => 'John Doe', 'email' => 'john.b@example.test']);

        $this->artisan('contacts:backfill', ['--apply' => true])->assertExitCode(Command::SUCCESS);

        $this->assertSame(2, Contact::count());
        $this->assertNotSame($guestA->fresh()->contact_id, $guestB->fresh()->contact_id);
    }

    public function test_guests_without_identifiers_remain_separate(): void
    {
        $owner = User::factory()->create();
        $eventA = $this->event($owner);
        $eventB = $this->event($owner);
        $guestA = $this->guest($eventA, ['name' => 'Same Name', 'email' => null, 'phone' => null]);
        $guestB = $this->guest($eventB, ['name' => 'Same Name', 'email' => null, 'phone' => null]);

        $this->artisan('contacts:backfill', ['--apply' => true])->assertExitCode(Command::SUCCESS);

        $this->assertSame(2, Contact::count());
        $this->assertNotNull($guestA->fresh()->contact_id);
        $this->assertNotNull($guestB->fresh()->contact_id);
        $this->assertNotSame($guestA->fresh()->contact_id, $guestB->fresh()->contact_id);
    }

    public function test_email_and_phone_disagreement_is_flagged_without_merging(): void
    {
        $owner = User::factory()->create();
        $event = $this->event($owner);

        Contact::factory()->create([
            'user_id' => $owner->id,
            'email' => 'a@example.test',
            'normalized_email' => 'a@example.test',
            'phone' => null,
            'normalized_phone' => null,
        ]);
        Contact::factory()->create([
            'user_id' => $owner->id,
            'email' => null,
            'normalized_email' => null,
            'phone' => '+255700000000',
            'normalized_phone' => '255700000000',
        ]);

        $guest = $this->guest($event, ['email' => 'a@example.test', 'phone' => '+255700000000']);

        $this->artisan('contacts:backfill', ['--apply' => true])
            ->expectsOutputToContain('email matches contact')
            ->assertExitCode(Command::SUCCESS);

        $this->assertNull($guest->fresh()->contact_id);
        $this->assertSame(2, Contact::count());
    }

    public function test_existing_guest_links_are_preserved(): void
    {
        $owner = User::factory()->create();
        $event = $this->event($owner);
        $existing = Contact::factory()->create([
            'user_id' => $owner->id,
            'email' => null,
            'normalized_email' => null,
            'phone' => null,
            'normalized_phone' => null,
        ]);
        $guest = $this->guest($event, ['contact_id' => $existing->id, 'email' => 'new@example.test']);

        $this->artisan('contacts:backfill', ['--apply' => true])->assertExitCode(Command::SUCCESS);

        $this->assertSame($existing->id, $guest->fresh()->contact_id);
        $this->assertSame(1, Contact::count());
    }

    public function test_existing_inconsistent_link_is_reported_not_repaired(): void
    {
        $owner = User::factory()->create();
        $event = $this->event($owner);
        $contact = Contact::factory()->create([
            'user_id' => $owner->id,
            'email' => 'old@example.test',
            'normalized_email' => 'old@example.test',
            'phone' => null,
            'normalized_phone' => null,
        ]);
        $guest = $this->guest($event, ['contact_id' => $contact->id, 'email' => 'new@example.test']);

        $this->artisan('contacts:backfill', ['--apply' => true])
            ->expectsOutputToContain('contradicts')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame($contact->id, $guest->fresh()->contact_id);
    }

    public function test_rollback_detaches_only_links_created_by_the_run(): void
    {
        $owner = User::factory()->create();
        $eventA = $this->event($owner);
        $eventB = $this->event($owner);

        $preExisting = Contact::factory()->create([
            'user_id' => $owner->id,
            'email' => null,
            'normalized_email' => null,
            'phone' => null,
            'normalized_phone' => null,
        ]);
        $preGuest = $this->guest($eventA, ['contact_id' => $preExisting->id, 'email' => 'keep@example.test']);
        $newGuest = $this->guest($eventB, ['email' => 'backfilled@example.test']);

        $this->artisan('contacts:backfill', ['--apply' => true, '--run' => 'run-1'])
            ->assertExitCode(Command::SUCCESS);

        $newGuest->refresh();
        $this->assertNotNull($newGuest->contact_id);
        $newContactId = $newGuest->contact_id;

        // Preview does not change anything.
        $this->artisan('contacts:backfill-rollback', ['run' => 'run-1'])->assertExitCode(Command::SUCCESS);
        $this->assertNotNull($newGuest->fresh()->contact_id);

        // Apply detaches only the backfilled link.
        $this->artisan('contacts:backfill-rollback', ['run' => 'run-1', '--apply' => true])
            ->assertExitCode(Command::SUCCESS);

        $this->assertNull($newGuest->fresh()->contact_id);
        $this->assertSame($preExisting->id, $preGuest->fresh()->contact_id);

        // Contacts are never deleted by rollback.
        $this->assertDatabaseHas('contacts', ['id' => $newContactId]);
        $this->assertDatabaseHas('contacts', ['id' => $preExisting->id]);
    }

    public function test_apply_preserves_guest_fields_tokens_rsvps_and_deliveries(): void
    {
        $owner = User::factory()->create();
        $event = $this->event($owner);
        $guest = $this->guest($event, [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'phone' => '+255700000001',
            'status' => 'accepted',
        ]);

        Rsvp::create(['guest_id' => $guest->id, 'attending_count' => 2, 'answers' => ['meal' => 'vegetarian']]);
        InvitationDelivery::create([
            'guest_id' => $guest->id,
            'kind' => 'invitation',
            'channel' => 'mail',
            'status' => 'sent',
            'provider_message_id' => 'prov-1',
            'sent_at' => now(),
        ]);

        $token = $guest->invite_token;

        $this->artisan('contacts:backfill', ['--apply' => true])->assertExitCode(Command::SUCCESS);

        $guest->refresh();
        $this->assertSame('Ada Lovelace', $guest->name);
        $this->assertSame('ada@example.test', $guest->email);
        $this->assertSame('+255700000001', $guest->phone);
        $this->assertSame('accepted', $guest->status);
        $this->assertSame($token, $guest->invite_token);
        $this->assertNotNull($guest->contact_id);

        $this->assertSame(1, Rsvp::where('guest_id', $guest->id)->count());
        $this->assertSame(2, (int) Rsvp::where('guest_id', $guest->id)->value('attending_count'));
        $this->assertSame(1, InvitationDelivery::where('guest_id', $guest->id)->count());
        $this->assertSame('prov-1', InvitationDelivery::where('guest_id', $guest->id)->value('provider_message_id'));
    }

    public function test_verify_passes_after_a_clean_apply(): void
    {
        $owner = User::factory()->create();
        $event = $this->event($owner);
        $this->guest($event, ['email' => 'clean@example.test']);

        $this->artisan('contacts:backfill', ['--apply' => true, '--run' => 'run-clean'])
            ->assertExitCode(Command::SUCCESS);

        $this->artisan('contacts:verify', ['--run' => 'run-clean'])->assertExitCode(Command::SUCCESS);
    }

    public function test_verify_detects_unexpected_changes(): void
    {
        $owner = User::factory()->create();
        $event = $this->event($owner);
        $guest = $this->guest($event, ['email' => 'verify@example.test']);

        $this->artisan('contacts:backfill', ['--apply' => true, '--run' => 'run-verify'])
            ->assertExitCode(Command::SUCCESS);

        // Tamper with a field the backfill must never change.
        $guest->forceFill(['name' => 'Tampered Name'])->save();

        $this->artisan('contacts:verify', ['--run' => 'run-verify'])->assertExitCode(Command::FAILURE);
    }

    public function test_failure_is_atomic_and_reported(): void
    {
        $owner = User::factory()->create();
        $event = $this->event($owner);
        $this->guest($event, ['email' => 'a@example.test']);
        $this->guest($event, ['email' => 'b@example.test']);

        // Force a failure while creating the first contact.
        Contact::creating(function () {
            throw new RuntimeException('boom');
        });

        try {
            $this->artisan('contacts:backfill', ['--apply' => true, '--run' => 'boom'])
                ->expectsOutputToContain('Backfill failed')
                ->assertExitCode(Command::FAILURE);
        } finally {
            Contact::flushEventListeners();
        }

        // Nothing was committed.
        $this->assertSame(0, Contact::count());
        $this->assertSame(0, Guest::whereNotNull('contact_id')->count());

        // The failure is recorded, with no links to roll back.
        $this->assertTrue(Storage::disk('local')->exists('contacts-backfill/boom.json'));
        $report = json_decode((string) Storage::disk('local')->get('contacts-backfill/boom.json'), true);
        $this->assertSame('failed', $report['status']);
        $this->assertSame([], $report['links']);
    }

    public function test_supplied_run_id_is_rejected_when_journal_exists(): void
    {
        $owner = User::factory()->create();
        $event = $this->event($owner);
        $this->guest($event, ['email' => 'dup@example.test']);

        $this->artisan('contacts:backfill', ['--apply' => true, '--run' => 'dup-run'])
            ->assertExitCode(Command::SUCCESS);

        $journal = Storage::disk('local')->get('contacts-backfill/dup-run.json');
        $contacts = Contact::count();

        $this->artisan('contacts:backfill', ['--apply' => true, '--run' => 'dup-run'])
            ->expectsOutputToContain('already exists')
            ->assertExitCode(Command::FAILURE);

        // Nothing changed and the journal was not overwritten.
        $this->assertSame($contacts, Contact::count());
        $this->assertSame($journal, Storage::disk('local')->get('contacts-backfill/dup-run.json'));
    }

    public function test_failed_run_journal_is_never_overwritten(): void
    {
        $owner = User::factory()->create();
        $event = $this->event($owner);
        $this->guest($event, ['email' => 'boom@example.test']);

        Contact::creating(function () {
            throw new RuntimeException('boom');
        });

        try {
            $this->artisan('contacts:backfill', ['--apply' => true, '--run' => 'failed-run'])
                ->assertExitCode(Command::FAILURE);
        } finally {
            Contact::flushEventListeners();
        }

        $this->assertTrue(Storage::disk('local')->exists('contacts-backfill/failed-run.json'));

        // A failed journal still reserves its run id and must not be reused.
        $this->artisan('contacts:backfill', ['--apply' => true, '--run' => 'failed-run'])
            ->expectsOutputToContain('already exists')
            ->assertExitCode(Command::FAILURE);

        $this->assertSame(0, Contact::count());
    }

    public function test_generated_run_ids_are_unique(): void
    {
        $owner = User::factory()->create();
        $event = $this->event($owner);
        $this->guest($event, ['email' => 'unique@example.test']);

        $this->artisan('contacts:backfill', ['--apply' => true])->assertExitCode(Command::SUCCESS);
        $this->artisan('contacts:backfill', ['--apply' => true])->assertExitCode(Command::SUCCESS);

        $journals = Storage::disk('local')->files('contacts-backfill');
        $this->assertCount(2, $journals);
        $this->assertSame($journals, array_unique($journals));
    }

    public function test_rollback_does_not_modify_the_run_journal(): void
    {
        $owner = User::factory()->create();
        $event = $this->event($owner);
        $guest = $this->guest($event, ['email' => 'immutable@example.test']);

        $this->artisan('contacts:backfill', ['--apply' => true, '--run' => 'immutable-run'])
            ->assertExitCode(Command::SUCCESS);

        $before = Storage::disk('local')->get('contacts-backfill/immutable-run.json');

        $this->artisan('contacts:backfill-rollback', ['run' => 'immutable-run', '--apply' => true])
            ->assertExitCode(Command::SUCCESS);

        $this->assertNull($guest->fresh()->contact_id);
        $this->assertSame($before, Storage::disk('local')->get('contacts-backfill/immutable-run.json'));
    }

    public function test_verify_detects_import_reference_change_with_same_count(): void
    {
        $owner = User::factory()->create();
        $eventA = $this->event($owner);
        $eventB = $this->event($owner);
        $this->guest($eventA, ['email' => 'import@example.test']);

        $import = GuestImport::create([
            'event_id' => $eventA->id,
            'user_id' => $owner->id,
            'original_filename' => 'guests.csv',
            'status' => 'completed',
            'created_count' => 1,
            'skipped_count' => 0,
            'failed_count' => 0,
        ]);

        $this->artisan('contacts:backfill', ['--apply' => true, '--run' => 'run-import'])
            ->assertExitCode(Command::SUCCESS);

        $this->artisan('contacts:verify', ['--run' => 'run-import'])->assertExitCode(Command::SUCCESS);

        // Same number of import rows, but a reference changed.
        $import->update(['event_id' => $eventB->id]);
        $this->assertSame(1, GuestImport::count());

        $this->artisan('contacts:verify', ['--run' => 'run-import'])->assertExitCode(Command::FAILURE);
    }

    public function test_verify_detects_activity_log_reference_change_with_same_count(): void
    {
        $owner = User::factory()->create();
        $event = $this->event($owner);
        $guest = $this->guest($event, ['email' => 'log@example.test']);

        $log = ActivityLog::create([
            'user_id' => $owner->id,
            'action' => 'guests.create',
            'auditable_type' => Guest::class,
            'auditable_id' => $guest->id,
            'meta' => ['note' => 'x'],
        ]);

        $this->artisan('contacts:backfill', ['--apply' => true, '--run' => 'run-log'])
            ->assertExitCode(Command::SUCCESS);

        $this->artisan('contacts:verify', ['--run' => 'run-log'])->assertExitCode(Command::SUCCESS);

        // Same number of log rows, but the auditable reference changed.
        $log->update(['auditable_id' => 999999]);
        $this->assertSame(1, ActivityLog::count());

        $this->artisan('contacts:verify', ['--run' => 'run-log'])->assertExitCode(Command::FAILURE);
    }
}
