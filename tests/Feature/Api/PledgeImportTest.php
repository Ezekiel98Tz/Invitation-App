<?php

namespace Tests\Feature\Api;

use App\Models\Contact;
use App\Models\Event;
use App\Models\Guest;
use App\Models\Pledge;
use App\Models\PledgeFulfillment;
use App\Models\PledgeImportBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class PledgeImportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<string>
     */
    private const HEADER = [
        'pledger_name',
        'pledger_email',
        'pledger_phone',
        'currency',
        'pledged_amount_minor',
        'fulfillment_amount_minor',
        'received_on',
        'reference',
        'method',
        'note',
    ];

    private function owner(): User
    {
        return User::factory()->create();
    }

    private function event(User $owner): Event
    {
        return Event::factory()->create([
            'user_id' => $owner->id,
            'event_start' => now()->addDays(7),
            // Pledge import/export endpoints require the `contributions`
            // capability.
            'settings' => [
                'capabilities' => ['contributions' => true],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function guest(Event $event, array $attributes = []): Guest
    {
        return Guest::factory()->create(array_merge([
            'event_id' => $event->id,
            'status' => 'pending',
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function pledge(Event $event, array $attributes = []): Pledge
    {
        return Pledge::factory()->create(array_merge([
            'user_id' => $event->user_id,
            'event_id' => $event->id,
            'currency' => 'USD',
            'pledged_amount_minor' => 1000,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function batch(Event $event, array $attributes = []): PledgeImportBatch
    {
        return PledgeImportBatch::create(array_merge([
            'user_id' => $event->user_id,
            'event_id' => $event->id,
            'original_filename' => 'pledges.csv',
            'status' => PledgeImportBatch::STATUS_UPLOADED,
            'columns' => self::HEADER,
            'mapping' => $this->mapping(),
        ], $attributes));
    }

    /**
     * @return array<string, string>
     */
    private function mapping(): array
    {
        return [
            'pledger_name' => 'pledger_name',
            'pledger_email' => 'pledger_email',
            'pledger_phone' => 'pledger_phone',
            'currency' => 'currency',
            'pledged_amount_minor' => 'pledged_amount_minor',
            'amount_minor' => 'fulfillment_amount_minor',
            'received_on' => 'received_on',
            'reference' => 'reference',
            'method' => 'method',
            'note' => 'note',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function csv(array $rows): string
    {
        $lines = [implode(',', self::HEADER)];

        foreach ($rows as $row) {
            $cells = [];

            foreach (self::HEADER as $column) {
                $value = (string) ($row[$column] ?? '');
                $cells[] = str_replace([',', '"', "\n", "\r"], [' ', '', ' ', ''], $value);
            }

            $lines[] = implode(',', $cells);
        }

        return implode("\n", $lines);
    }

    private function upload(Event $event, User $user, string $content, string $filename = 'pledges.csv')
    {
        return $this->actingAs($user)
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('api.events.pledges.import.upload', ['event' => $event->id]), [
                'file' => UploadedFile::fake()->createWithContent($filename, $content),
            ]);
    }

    private function uploadBatchId(Event $event, User $user, string $content): int
    {
        return (int) $this->upload($event, $user, $content)->assertStatus(201)->json('batch.id');
    }

    // ------------------------------------------------------------- authentication

    public function test_import_and_export_endpoints_require_authentication(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $batch = $this->batch($event);

        $this->postJson(route('api.events.pledges.import.upload', $event), [])->assertStatus(401);
        $this->getJson(route('api.events.pledges.import.show', [$event, $batch]))->assertStatus(401);
        $this->postJson(route('api.events.pledges.import.preview', [$event, $batch]), [])->assertStatus(401);
        $this->postJson(route('api.events.pledges.import.confirm', [$event, $batch]), [])->assertStatus(401);
        $this->getJson(route('api.events.pledges.import.errors', [$event, $batch]))->assertStatus(401);
        $this->getJson(route('api.events.pledges.import.template', $event))->assertStatus(401);
        $this->getJson(route('api.events.pledges.export', $event))->assertStatus(401);
    }

    public function test_import_and_export_are_forbidden_for_another_users_event(): void
    {
        $owner = $this->owner();
        $intruder = $this->owner();
        $event = $this->event($owner);
        $batch = $this->batch($event);

        $this->actingAs($intruder)
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('api.events.pledges.import.upload', $event), [
                'file' => UploadedFile::fake()->createWithContent('pledges.csv', $this->csv([])),
            ])
            ->assertStatus(403);

        $this->actingAs($intruder)->getJson(route('api.events.pledges.import.show', [$event, $batch]))->assertStatus(403);
        $this->actingAs($intruder)->postJson(route('api.events.pledges.import.preview', [$event, $batch]), [])->assertStatus(403);
        $this->actingAs($intruder)->postJson(route('api.events.pledges.import.confirm', [$event, $batch]), [])->assertStatus(403);
        $this->actingAs($intruder)->getJson(route('api.events.pledges.import.errors', [$event, $batch]))->assertStatus(403);
        $this->actingAs($intruder)->getJson(route('api.events.pledges.import.template', $event))->assertStatus(403);
        $this->actingAs($intruder)->getJson(route('api.events.pledges.export', $event))->assertStatus(403);
    }

    public function test_import_is_rejected_for_an_unsupported_file_type(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);

        $this->actingAs($owner)
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('api.events.pledges.import.upload', $event), [
                'file' => UploadedFile::fake()->createWithContent('pledges.txt', "pledger_name\nAlice"),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    // -------------------------------------------------------------------- upload

    public function test_upload_returns_columns_and_a_suggested_mapping_without_touching_pledges(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);

        $response = $this->upload($event, $owner, $this->csv([
            ['pledger_name' => 'Alice', 'pledger_email' => 'alice@example.test', 'currency' => 'USD', 'pledged_amount_minor' => 1000],
        ]));

        $response
            ->assertStatus(201)
            ->assertJsonPath('batch.status', 'uploaded')
            ->assertJsonPath('mapping.pledger_name', 'pledger_name')
            ->assertJsonPath('mapping.amount_minor', 'fulfillment_amount_minor');

        $columns = $response->json('columns');
        $this->assertContains('pledger_name', $columns);
        $this->assertContains('pledged_amount_minor', $columns);

        $this->assertDatabaseCount('pledges', 0);
        $this->assertDatabaseHas('pledge_import_batches', [
            'id' => $response->json('batch.id'),
            'event_id' => $event->id,
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'status' => 'uploaded',
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $owner->id,
            'action' => 'pledge_import.upload',
        ]);
    }

    // ------------------------------------------------------------------- preview

    public function test_preview_does_not_mutate_pledges(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $batchId = $this->uploadBatchId($event, $owner, $this->csv([
            ['pledger_name' => 'Alice', 'pledger_email' => 'alice@example.test', 'currency' => 'USD', 'pledged_amount_minor' => 1000],
            ['pledger_name' => 'Bob', 'pledger_email' => 'bob@example.test', 'currency' => 'USD', 'pledged_amount_minor' => 2000],
        ]));

        $response = $this->actingAs($owner)->postJson(
            route('api.events.pledges.import.preview', [$event, $batchId]),
            ['mapping' => $this->mapping(), 'mode' => 'create_only'],
        );

        $response
            ->assertOk()
            ->assertJsonPath('counts.created', 2)
            ->assertJsonPath('counts.failed', 0)
            ->assertJsonPath('rows.0.action', 'create')
            ->assertJsonPath('rows.1.action', 'create')
            ->assertJsonPath('batch.status', 'previewed');

        $this->assertDatabaseCount('pledges', 0);
    }

    public function test_invalid_rows_are_reported_with_row_numbers_and_are_not_imported(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $batchId = $this->uploadBatchId($event, $owner, $this->csv([
            ['pledger_name' => '', 'pledger_email' => 'x@example.test', 'currency' => 'USD', 'pledged_amount_minor' => 1000],
            ['pledger_name' => 'Negative', 'currency' => 'USD', 'pledged_amount_minor' => -5],
            ['pledger_name' => 'BadCurrency', 'currency' => 'US', 'pledged_amount_minor' => 1000],
            ['pledger_name' => 'BadDate', 'currency' => 'USD', 'pledged_amount_minor' => 1000, 'fulfillment_amount_minor' => 500, 'received_on' => '2026-13-40'],
            ['pledger_name' => 'Good', 'pledger_email' => 'good@example.test', 'currency' => 'USD', 'pledged_amount_minor' => 1000],
        ]));

        $preview = $this->actingAs($owner)->postJson(
            route('api.events.pledges.import.preview', [$event, $batchId]),
            ['mapping' => $this->mapping()],
        );

        $preview
            ->assertOk()
            ->assertJsonPath('counts.failed', 4)
            ->assertJsonPath('counts.created', 1)
            ->assertJsonPath('rows.0.row', 1)
            ->assertJsonPath('rows.0.action', 'failed')
            ->assertJsonPath('rows.3.row', 4);

        $this->assertArrayHasKey('pledger_name', $preview->json('rows.0.errors'));
        $this->assertArrayHasKey('pledged_amount_minor', $preview->json('rows.1.errors'));
        $this->assertArrayHasKey('currency', $preview->json('rows.2.errors'));
        $this->assertArrayHasKey('received_on', $preview->json('rows.3.errors'));

        $this->actingAs($owner)->postJson(
            route('api.events.pledges.import.confirm', [$event, $batchId]),
            ['mapping' => $this->mapping()],
        )->assertOk()
            ->assertJsonPath('counts.failed', 4)
            ->assertJsonPath('counts.created', 1);

        $this->assertDatabaseCount('pledges', 1);
        $this->assertDatabaseHas('pledges', ['pledger_name' => 'Good', 'source' => 'import']);
    }

    // ------------------------------------------------------------------- confirm

    public function test_valid_confirm_creates_pledges_with_lineage_attribution_and_audit(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $batchId = $this->uploadBatchId($event, $owner, $this->csv([
            ['pledger_name' => 'Alice', 'pledger_email' => 'alice@example.test', 'currency' => 'USD', 'pledged_amount_minor' => 1000, 'note' => 'row one'],
            ['pledger_name' => 'Bob', 'pledger_email' => 'bob@example.test', 'currency' => 'TZS', 'pledged_amount_minor' => 2000],
        ]));

        $this->actingAs($owner)->postJson(
            route('api.events.pledges.import.confirm', [$event, $batchId]),
            ['mapping' => $this->mapping(), 'mode' => 'create_only'],
        )->assertOk()
            ->assertJsonPath('counts.created', 2)
            ->assertJsonPath('batch.status', 'completed')
            ->assertJsonPath('batch.summary.created', 2);

        $this->assertDatabaseCount('pledges', 2);

        $alice = Pledge::where('event_id', $event->id)->where('pledger_name', 'Alice')->firstOrFail();
        $this->assertSame('import', $alice->source);
        $this->assertSame($batchId, $alice->import_batch_id);
        $this->assertSame($owner->id, $alice->created_by);
        $this->assertSame($owner->id, $alice->user_id);
        $this->assertSame('USD', $alice->currency);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $owner->id,
            'action' => 'pledge_import.confirm',
            'auditable_type' => PledgeImportBatch::class,
            'auditable_id' => $batchId,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $owner->id,
            'action' => 'pledge.create',
            'auditable_id' => $alice->id,
        ]);
    }

    public function test_explicit_update_of_a_matched_pledge_and_create_only_skip(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $guest = $this->guest($event, ['email' => 'matched@example.test']);
        $pledge = $this->pledge($event, [
            'guest_id' => $guest->id,
            'pledger_name' => 'Old Name',
            'pledged_amount_minor' => 1000,
        ]);

        $content = $this->csv([
            ['pledger_name' => 'New Name', 'pledger_email' => 'matched@example.test', 'currency' => 'USD', 'pledged_amount_minor' => 5000],
        ]);

        // create_only: matched pledge is skipped, never updated or duplicated.
        $batchA = $this->uploadBatchId($event, $owner, $content);

        $preview = $this->actingAs($owner)->postJson(
            route('api.events.pledges.import.preview', [$event, $batchA]),
            ['mapping' => $this->mapping(), 'mode' => 'update_matched'],
        );
        $preview->assertOk()
            ->assertJsonPath('rows.0.action', 'update')
            ->assertJsonPath('rows.0.guest_id', $guest->id)
            ->assertJsonPath('rows.0.pledge_id', $pledge->id);

        $this->actingAs($owner)->postJson(
            route('api.events.pledges.import.confirm', [$event, $batchA]),
            ['mapping' => $this->mapping(), 'mode' => 'create_only'],
        )->assertOk()->assertJsonPath('counts.skipped', 1);

        $this->assertSame('Old Name', $pledge->fresh()->pledger_name);
        $this->assertSame(1000, (int) $pledge->fresh()->pledged_amount_minor);
        $this->assertDatabaseCount('pledges', 1);

        // update_matched: matched pledge is explicitly updated in place.
        $batchB = $this->uploadBatchId($event, $owner, $content);

        $this->actingAs($owner)->postJson(
            route('api.events.pledges.import.confirm', [$event, $batchB]),
            ['mapping' => $this->mapping(), 'mode' => 'update_matched'],
        )->assertOk()->assertJsonPath('counts.updated', 1);

        $pledge->refresh();
        $this->assertSame('New Name', $pledge->pledger_name);
        $this->assertSame(5000, (int) $pledge->pledged_amount_minor);
        $this->assertDatabaseCount('pledges', 1);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $owner->id,
            'action' => 'pledge.update',
            'auditable_id' => $pledge->id,
        ]);
    }

    public function test_ambiguous_match_is_surfaced_and_never_merged_without_a_decision(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $guest = $this->guest($event, ['email' => 'ambiguous@example.test']);

        $first = $this->pledge($event, ['guest_id' => $guest->id, 'pledged_amount_minor' => 1000]);
        $second = $this->pledge($event, ['guest_id' => $guest->id, 'pledged_amount_minor' => 2000]);

        $batchId = $this->uploadBatchId($event, $owner, $this->csv([
            ['pledger_name' => 'Ambiguous', 'pledger_email' => 'ambiguous@example.test', 'currency' => 'USD', 'pledged_amount_minor' => 5000],
        ]));

        $this->actingAs($owner)->postJson(
            route('api.events.pledges.import.preview', [$event, $batchId]),
            ['mapping' => $this->mapping()],
        )->assertOk()
            ->assertJsonPath('rows.0.action', 'ambiguous')
            ->assertJsonPath('counts.ambiguous', 1);

        // Without an explicit decision the ambiguous row is skipped (not merged).
        $this->actingAs($owner)->postJson(
            route('api.events.pledges.import.confirm', [$event, $batchId]),
            ['mapping' => $this->mapping(), 'mode' => 'update_matched'],
        )->assertOk()
            ->assertJsonPath('counts.updated', 0)
            ->assertJsonPath('counts.skipped', 1)
            ->assertJsonPath('counts.ambiguous', 1);

        $this->assertDatabaseCount('pledges', 2);
        $this->assertSame(1000, (int) $first->fresh()->pledged_amount_minor);
        $this->assertSame(2000, (int) $second->fresh()->pledged_amount_minor);

        // An explicit resolution updates only the chosen pledge.
        $batchB = $this->uploadBatchId($event, $owner, $this->csv([
            ['pledger_name' => 'Resolved', 'pledger_email' => 'ambiguous@example.test', 'currency' => 'USD', 'pledged_amount_minor' => 5000],
        ]));

        $this->actingAs($owner)->postJson(
            route('api.events.pledges.import.confirm', [$event, $batchB]),
            [
                'mapping' => $this->mapping(),
                'mode' => 'update_matched',
                'decisions' => [1 => ['action' => 'update', 'pledge_id' => $first->id]],
            ],
        )->assertOk()->assertJsonPath('counts.updated', 1);

        $this->assertSame(5000, (int) $first->fresh()->pledged_amount_minor);
        $this->assertSame('Resolved', $first->fresh()->pledger_name);
        $this->assertSame(2000, (int) $second->fresh()->pledged_amount_minor);
        $this->assertDatabaseCount('pledges', 2);
    }

    public function test_duplicate_rows_are_skipped_and_reimport_does_not_duplicate(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);

        $content = $this->csv([
            ['pledger_name' => 'Alice', 'pledger_email' => 'alice@example.test', 'currency' => 'USD', 'pledged_amount_minor' => 1000],
            ['pledger_name' => 'Alice', 'pledger_email' => 'alice@example.test', 'currency' => 'USD', 'pledged_amount_minor' => 1000],
        ]);

        $batchA = $this->uploadBatchId($event, $owner, $content);
        $this->actingAs($owner)->postJson(
            route('api.events.pledges.import.confirm', [$event, $batchA]),
            ['mapping' => $this->mapping()],
        )->assertOk()
            ->assertJsonPath('counts.created', 1)
            ->assertJsonPath('counts.skipped', 1);

        $this->assertDatabaseCount('pledges', 1);

        // Re-upload the same file: no duplicate pledges are created.
        $batchB = $this->uploadBatchId($event, $owner, $content);
        $this->actingAs($owner)->postJson(
            route('api.events.pledges.import.confirm', [$event, $batchB]),
            ['mapping' => $this->mapping(), 'mode' => 'create_only'],
        )->assertOk()
            ->assertJsonPath('counts.created', 0)
            ->assertJsonPath('counts.skipped', 2);

        $this->assertDatabaseCount('pledges', 1);
    }

    public function test_fulfillment_is_imported_and_not_duplicated_on_reimport(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);

        $content = $this->csv([
            [
                'pledger_name' => 'Bob',
                'pledger_email' => 'bob@example.test',
                'currency' => 'USD',
                'pledged_amount_minor' => 10000,
                'fulfillment_amount_minor' => 4000,
                'received_on' => '2026-01-01',
                'reference' => 'REF-1',
                'method' => 'cash',
            ],
        ]);

        $batchA = $this->uploadBatchId($event, $owner, $content);
        $this->actingAs($owner)->postJson(
            route('api.events.pledges.import.confirm', [$event, $batchA]),
            ['mapping' => $this->mapping()],
        )->assertOk()
            ->assertJsonPath('counts.created', 1)
            ->assertJsonPath('counts.fulfillments_created', 1);

        $pledge = Pledge::where('event_id', $event->id)->firstOrFail();
        $this->assertDatabaseCount('pledges', 1);
        $this->assertDatabaseCount('pledge_fulfillments', 1);
        $this->assertDatabaseHas('pledge_fulfillments', [
            'pledge_id' => $pledge->id,
            'amount_minor' => 4000,
            'received_on' => '2026-01-01',
            'reference' => 'REF-1',
            'method' => 'cash',
            'status' => 'recorded',
        ]);

        // Re-import with update_matched: pledge updated, fulfillment de-duplicated.
        $batchB = $this->uploadBatchId($event, $owner, $content);
        $this->actingAs($owner)->postJson(
            route('api.events.pledges.import.confirm', [$event, $batchB]),
            ['mapping' => $this->mapping(), 'mode' => 'update_matched'],
        )->assertOk()
            ->assertJsonPath('counts.updated', 1)
            ->assertJsonPath('counts.fulfillments_created', 0)
            ->assertJsonPath('counts.fulfillments_skipped', 1);

        $this->assertDatabaseCount('pledge_fulfillments', 1);

        // Re-import with create_only: nothing is added at all.
        $batchC = $this->uploadBatchId($event, $owner, $content);
        $this->actingAs($owner)->postJson(
            route('api.events.pledges.import.confirm', [$event, $batchC]),
            ['mapping' => $this->mapping(), 'mode' => 'create_only'],
        )->assertOk()->assertJsonPath('counts.skipped', 1);

        $this->assertDatabaseCount('pledge_fulfillments', 1);
    }

    public function test_rollback_on_failure_keeps_guests_and_contacts_intact(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $guest = $this->guest($event, ['email' => 'kept-guest@example.test']);
        $contact = Contact::factory()->create([
            'user_id' => $owner->id,
            'email' => 'kept-contact@example.test',
            'normalized_email' => 'kept-contact@example.test',
        ]);

        $guestCount = Guest::count();
        $contactCount = Contact::count();

        $batchId = $this->uploadBatchId($event, $owner, $this->csv([
            ['pledger_name' => 'Row One', 'pledger_email' => 'row-one@example.test', 'currency' => 'USD', 'pledged_amount_minor' => 1000],
            ['pledger_name' => 'Row Two', 'pledger_email' => 'row-two@example.test', 'currency' => 'USD', 'pledged_amount_minor' => 2000],
        ]));

        // Row one would create a pledge; row two is forced to update a pledge that
        // does not exist in this event, which must fail the whole batch.
        $this->actingAs($owner)->postJson(
            route('api.events.pledges.import.confirm', [$event, $batchId]),
            [
                'mapping' => $this->mapping(),
                'decisions' => [2 => ['action' => 'update', 'pledge_id' => 999999]],
            ],
        )->assertStatus(422)->assertJsonValidationErrors('decisions');

        $this->assertDatabaseCount('pledges', 0);
        $this->assertSame($guestCount, Guest::count());
        $this->assertSame($contactCount, Contact::count());
        $this->assertDatabaseHas('guests', ['id' => $guest->id]);
        $this->assertDatabaseHas('contacts', ['id' => $contact->id]);

        // The batch was not left in a completed state.
        $this->assertNotSame(
            PledgeImportBatch::STATUS_COMPLETED,
            PledgeImportBatch::find($batchId)->status,
        );
    }

    public function test_staff_can_import_into_their_owners_event(): void
    {
        $owner = $this->owner();
        $staff = User::factory()->create(['role' => 'staff', 'owner_id' => $owner->id]);
        $event = $this->event($owner);

        $batchId = $this->uploadBatchId($event, $staff, $this->csv([
            ['pledger_name' => 'Staff Pledge', 'pledger_email' => 'staff-pledge@example.test', 'currency' => 'USD', 'pledged_amount_minor' => 1000],
        ]));

        $this->actingAs($staff)->postJson(
            route('api.events.pledges.import.confirm', [$event, $batchId]),
            ['mapping' => $this->mapping()],
        )->assertOk()->assertJsonPath('counts.created', 1);

        $pledge = Pledge::where('event_id', $event->id)->firstOrFail();
        $this->assertSame($owner->id, $pledge->user_id);
        $this->assertSame($staff->id, $pledge->created_by);
        $this->assertDatabaseHas('pledge_import_batches', ['id' => $batchId, 'user_id' => $owner->id, 'created_by' => $staff->id]);
    }

    // ------------------------------------------------------------------ downloads

    public function test_error_report_downloads_a_csv_of_problem_rows(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $batchId = $this->uploadBatchId($event, $owner, $this->csv([
            ['pledger_name' => '', 'currency' => 'USD', 'pledged_amount_minor' => 1000],
            ['pledger_name' => 'Fine', 'pledger_email' => 'fine@example.test', 'currency' => 'USD', 'pledged_amount_minor' => 1000],
        ]));

        $response = $this->actingAs($owner)
            ->get(route('api.events.pledges.import.errors', ['event' => $event->id, 'batch' => $batchId, 'format' => 'csv']));

        $response->assertOk()->assertDownload();

        $file = $response->baseResponse->getFile()->getPathname();
        $this->assertFileExists($file);

        $contents = (string) file_get_contents($file);
        $this->assertStringContainsString('errors', $contents);
        $this->assertStringContainsString('A pledger name is required.', $contents);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $owner->id,
            'action' => 'pledge_import.errors',
            'auditable_id' => $batchId,
        ]);

        $this->assertDatabaseCount('pledges', 0);
    }

    public function test_template_downloads_an_xlsx(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The PHP zip extension is required for XLSX exports.');
        }

        $owner = $this->owner();
        $event = $this->event($owner);

        $response = $this->actingAs($owner)->get(route('api.events.pledges.import.template', ['event' => $event->id]));

        $response->assertOk()->assertDownload();

        $file = $response->baseResponse->getFile()->getPathname();
        $this->assertFileExists($file);
        $this->assertSame('PK', substr((string) file_get_contents($file), 0, 2));
    }

    public function test_export_downloads_event_pledges_with_fulfillment(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The PHP zip extension is required for XLSX exports.');
        }

        $owner = $this->owner();
        $event = $this->event($owner);
        $pledge = $this->pledge($event, ['pledged_amount_minor' => 10000]);
        PledgeFulfillment::factory()->create(['pledge_id' => $pledge->id, 'amount_minor' => 4000, 'method' => 'cash']);

        $response = $this->actingAs($owner)->get(route('api.events.pledges.export', ['event' => $event->id]));

        $response->assertOk()->assertDownload();

        $file = $response->baseResponse->getFile()->getPathname();
        $this->assertFileExists($file);
        $this->assertSame('PK', substr((string) file_get_contents($file), 0, 2));

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $owner->id,
            'action' => 'pledge_import.export',
        ]);
    }
}
