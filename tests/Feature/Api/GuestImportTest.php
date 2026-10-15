<?php

namespace Tests\Feature\Api;

use App\Jobs\ProcessGuestImportJob;
use App\Models\Event;
use App\Models\Guest;
use App\Models\GuestImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GuestImportTest extends TestCase
{
    use RefreshDatabase;

    private function csv(): string
    {
        return implode("\n", [
            'name,email,phone',
            'Alice,alice@example.test,+255111',
            'Bob,,',
            ',noemail@example.test,',
            'Carol,carol@example.test,+255333',
        ]);
    }

    private function import(Event $event, User $user, string $filename, string $content)
    {
        return $this->actingAs($user)
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('api.events.guests.import', ['event' => $event->id]), [
                'file' => UploadedFile::fake()->createWithContent($filename, $content),
                'mapping' => ['name' => 'name', 'email' => 'email', 'phone' => 'phone'],
            ]);
    }

    public function test_import_requires_authentication(): void
    {
        $event = Event::factory()->create();

        $this->postJson(route('api.events.guests.import', ['event' => $event->id]), [])
            ->assertStatus(401);
    }

    public function test_import_is_forbidden_for_another_users_event(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($other)
            ->post(route('api.events.guests.import', ['event' => $event->id]), [
                'file' => UploadedFile::fake()->createWithContent('guests.csv', $this->csv()),
            ])
            ->assertStatus(403);
    }

    public function test_import_requires_a_file(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->postJson(route('api.events.guests.import', ['event' => $event->id]), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_import_rejects_unsupported_file_type(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $user->id]);

        $this->import($event, $user, 'guests.txt', "name\nAlice")
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_import_rejects_oversized_file(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('api.events.guests.import', ['event' => $event->id]), [
                'file' => UploadedFile::fake()->create('guests.csv', 6000),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_import_rejects_file_without_a_name_column(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $user->id]);

        $this->import($event, $user, 'guests.csv', "fullname,email\nAlice,alice@example.test")
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_valid_import_creates_guests_and_reports_status(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $user->id]);

        $response = $this->import($event, $user, 'guests.csv', $this->csv());
        $response->assertStatus(202)->assertJsonStructure(['import' => ['id', 'status']]);

        // QUEUE_CONNECTION=sync in tests, so the job has already run.
        $this->assertSame(3, Guest::where('event_id', $event->id)->count());
        $this->assertDatabaseHas('guests', [
            'event_id' => $event->id,
            'name' => 'Alice',
            'email' => 'alice@example.test',
            'status' => 'pending',
        ]);

        $bob = Guest::where('event_id', $event->id)->where('name', 'Bob')->first();
        $this->assertNotNull($bob);
        $this->assertNull($bob->email);
        $this->assertNull($bob->phone);

        $this->actingAs($user)
            ->getJson(route('api.events.imports.latest', ['event' => $event->id]))
            ->assertOk()
            ->assertJsonPath('import.status', 'completed')
            ->assertJsonPath('import.created_count', 3)
            ->assertJsonPath('import.skipped_count', 1);

        // Counts flow through the guest index.
        $this->actingAs($user)
            ->getJson(route('api.events.guests.index', ['event' => $event->id]))
            ->assertOk()
            ->assertJsonPath('counts.total', 3)
            ->assertJsonPath('counts.pending', 3);
    }

    public function test_duplicate_rows_are_skipped_not_duplicated(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $user->id]);

        $this->import($event, $user, 'guests.csv', $this->csv())->assertStatus(202);
        $this->assertSame(3, Guest::where('event_id', $event->id)->count());

        // Re-importing the same file must not create duplicates.
        $this->import($event, $user, 'guests.csv', $this->csv())->assertStatus(202);
        $this->assertSame(3, Guest::where('event_id', $event->id)->count());

        $this->actingAs($user)
            ->getJson(route('api.events.imports.latest', ['event' => $event->id]))
            ->assertOk()
            ->assertJsonPath('import.created_count', 0)
            ->assertJsonPath('import.skipped_count', 4);
    }

    public function test_import_job_marks_import_failed_when_file_cannot_be_read(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $user->id]);

        $import = GuestImport::create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'original_filename' => 'missing.csv',
            'status' => 'queued',
        ]);

        $job = new ProcessGuestImportJob($import->id, Storage::path('imports/missing-'.uniqid().'.csv'), []);

        try {
            $job->handle();
            $this->fail('Expected the import job to throw.');
        } catch (\Throwable $e) {
            // expected
        }

        $import->refresh();
        $this->assertSame('failed', $import->status);
        $this->assertNotNull($import->error);
        $this->assertNotNull($import->finished_at);
    }
}
