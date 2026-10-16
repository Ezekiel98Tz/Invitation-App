<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\SimpleExcel\SimpleExcelReader;
use Tests\TestCase;

class GuestExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_export_downloads_a_valid_xlsx(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $user->id]);
        Guest::factory()->count(3)->create(['event_id' => $event->id]);

        $response = $this->actingAs($user)
            ->get(route('api.events.guests.export', ['event' => $event->id]));

        $response->assertOk();
        $response->assertDownload();

        $file = $response->baseResponse->getFile()->getPathname();
        $this->assertFileExists($file);

        $contents = (string) file_get_contents($file);
        $this->assertNotSame('', $contents);
        // XLSX files are ZIP containers, so they begin with the PK signature.
        $this->assertSame('PK', substr($contents, 0, 2));
    }

    public function test_guest_export_succeeds_when_exports_directory_is_missing(): void
    {
        // Reproduces the original failure: the exports directory is absent.
        Storage::deleteDirectory('exports');
        $this->assertDirectoryDoesNotExist(Storage::path('exports'));

        $user = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $user->id]);
        Guest::factory()->create(['event_id' => $event->id]);

        $this->actingAs($user)
            ->get(route('api.events.guests.export', ['event' => $event->id]))
            ->assertOk()
            ->assertDownload();

        $this->assertDirectoryExists(Storage::path('exports'));
    }

    public function test_guest_export_does_not_include_the_invitation_token(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $user->id]);
        $guest = Guest::factory()->create(['event_id' => $event->id]);
        $token = (string) $guest->invite_token;

        $response = $this->actingAs($user)
            ->get(route('api.events.guests.export', ['event' => $event->id]))
            ->assertOk()
            ->assertDownload();

        $file = $response->baseResponse->getFile()->getPathname();
        $rows = SimpleExcelReader::create($file)->getRows()->all();

        $this->assertNotEmpty($rows);

        // The bearer RSVP token must not be exported; operators obtain the
        // invitation link/QR through the authenticated in-app workflow.
        $this->assertNotContains('invite_token', array_keys($rows[0]));
        $this->assertStringNotContainsString($token, (string) json_encode($rows));
    }

    public function test_guest_export_requires_authentication(): void
    {
        $event = Event::factory()->create();

        $this->getJson(route('api.events.guests.export', ['event' => $event->id]))
            ->assertStatus(401);
    }

    public function test_guest_export_is_forbidden_for_another_users_event(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($other)
            ->getJson(route('api.events.guests.export', ['event' => $event->id]))
            ->assertStatus(403);
    }
}
