<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Uploaded pledge source files must be stored on the private disk and must not
 * be publicly served (no public disk copy, no unsigned HTTP access). This is
 * evidence for the retention recommendation, not a cleanup job.
 */
class PledgeImportSourceTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create();
    }

    private function event(User $owner): Event
    {
        return Event::factory()->create([
            'user_id' => $owner->id,
            'event_start' => now()->addDays(7),
            'settings' => ['capabilities' => ['contributions' => true]],
        ]);
    }

    public function test_uploaded_source_is_stored_privately_and_is_not_publicly_served(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);

        $content = "pledger_name,currency,pledged_amount_minor\nAlice,USD,1000";

        $batchId = (int) $this->actingAs($owner)
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('api.events.pledges.import.upload', ['event' => $event->id]), [
                'file' => UploadedFile::fake()->createWithContent('pledges.csv', $content),
            ])
            ->assertStatus(201)
            ->json('batch.id');

        $path = 'pledge-imports/'.$batchId.'.csv';

        // Written to the default (private) disk only.
        $this->assertTrue(Storage::disk('local')->exists($path));
        $this->assertFalse(Storage::disk('public')->exists($path));
        $this->assertSame([], Storage::disk('public')->allFiles('pledge-imports'));

        // An unsigned request never serves the file: the private local disk
        // route requires a valid signature (403 in non-production, 404 in
        // production). It is never a 200.
        $status = $this->get('/storage/'.$path)->getStatusCode();
        $this->assertContains($status, [403, 404], 'A private source file must not be served without a signature.');

        // The file is still on disk — this suite never deletes stored files.
        $this->assertTrue(Storage::disk('local')->exists($path));
    }
}
