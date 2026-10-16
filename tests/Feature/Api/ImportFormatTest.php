<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Import error-report / export format contract.
 *
 *  - `format=csv` (the default) works unconditionally and never requires the
 *    PHP `zip` extension.
 *  - `format=xlsx` requires the PHP `zip` extension and degrades to a clear
 *    `503` JSON response (never a 500) when it is unavailable.
 *  - An unsupported/unknown `format` value falls back to CSV rather than
 *    erroring.
 */
class ImportFormatTest extends TestCase
{
    use RefreshDatabase;

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
            'settings' => ['capabilities' => ['contributions' => true]],
        ]);
    }

    private function csv(array $rows): string
    {
        $lines = [implode(',', self::HEADER)];

        foreach ($rows as $row) {
            $cells = [];

            foreach (self::HEADER as $column) {
                $cells[] = str_replace([',', '"', "\n", "\r"], [' ', '', ' ', ''], (string) ($row[$column] ?? ''));
            }

            $lines[] = implode(',', $cells);
        }

        return implode("\n", $lines);
    }

    /**
     * Upload a batch that contains one invalid row (so the error report is not
     * empty) and return its id.
     */
    private function problemBatchId(Event $event, User $owner): int
    {
        $content = $this->csv([
            ['pledger_name' => '', 'currency' => 'USD', 'pledged_amount_minor' => 1000],
            ['pledger_name' => 'Fine', 'pledger_email' => 'fine@example.test', 'currency' => 'USD', 'pledged_amount_minor' => 1000],
        ]);

        return (int) $this->actingAs($owner)
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('api.events.pledges.import.upload', ['event' => $event->id]), [
                'file' => UploadedFile::fake()->createWithContent('pledges.csv', $content),
            ])
            ->assertStatus(201)
            ->json('batch.id');
    }

    public function test_error_report_defaults_to_csv_when_no_format_is_given(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $batchId = $this->problemBatchId($event, $owner);

        $response = $this->actingAs($owner)->get(route('api.events.pledges.import.errors', [
            'event' => $event->id,
            'batch' => $batchId,
        ]));

        $response->assertOk()->assertDownload();

        $this->assertStringContainsString('.csv', (string) $response->headers->get('content-disposition'));
        $this->assertStringContainsString('A pledger name is required.', (string) file_get_contents($response->baseResponse->getFile()->getPathname()));
    }

    public function test_error_report_accepts_an_explicit_csv_format_case_insensitively(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $batchId = $this->problemBatchId($event, $owner);

        $response = $this->actingAs($owner)->get(route('api.events.pledges.import.errors', [
            'event' => $event->id,
            'batch' => $batchId,
            'format' => 'CSV',
        ]));

        $response->assertOk()->assertDownload();

        // A CSV download must not be a ZIP container.
        $this->assertNotSame('PK', substr((string) file_get_contents($response->baseResponse->getFile()->getPathname()), 0, 2));
    }

    public function test_unsupported_error_report_format_falls_back_to_csv(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $batchId = $this->problemBatchId($event, $owner);

        $response = $this->actingAs($owner)->get(route('api.events.pledges.import.errors', [
            'event' => $event->id,
            'batch' => $batchId,
            'format' => 'pdf',
        ]));

        // Never errors: an unknown format is served as CSV.
        $response->assertOk()->assertDownload();

        $this->assertStringContainsString('.csv', (string) $response->headers->get('content-disposition'));
        $this->assertStringContainsString('A pledger name is required.', (string) file_get_contents($response->baseResponse->getFile()->getPathname()));
    }

    public function test_xlsx_error_report_requires_the_zip_extension(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $batchId = $this->problemBatchId($event, $owner);

        $response = $this->actingAs($owner)->get(route('api.events.pledges.import.errors', [
            'event' => $event->id,
            'batch' => $batchId,
            'format' => 'xlsx',
        ]));

        if (extension_loaded('zip')) {
            $response->assertOk()->assertDownload();
            $this->assertSame('PK', substr((string) file_get_contents($response->baseResponse->getFile()->getPathname()), 0, 2));

            return;
        }

        // Graceful, explicit 503 — not a 500 from the writer.
        $response->assertStatus(503)
            ->assertJsonPath('message', 'XLSX export requires the PHP zip extension.');
    }

    public function test_xlsx_only_exports_degrade_to_503_without_the_zip_extension(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);

        $template = $this->actingAs($owner)->get(route('api.events.pledges.import.template', ['event' => $event->id]));
        $export = $this->actingAs($owner)->get(route('api.events.pledges.export', ['event' => $event->id]));

        if (extension_loaded('zip')) {
            $template->assertOk()->assertDownload();
            $export->assertOk()->assertDownload();

            return;
        }

        $template->assertStatus(503)->assertJsonPath('message', 'XLSX export requires the PHP zip extension.');
        $export->assertStatus(503)->assertJsonPath('message', 'XLSX export requires the PHP zip extension.');
    }

    public function test_guest_export_requires_the_zip_extension_consistently(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);

        $response = $this->actingAs($owner)->get(route('api.events.guests.export', ['event' => $event->id]));

        if (extension_loaded('zip')) {
            $response->assertOk()->assertDownload();

            return;
        }

        $response->assertStatus(503)
            ->assertJsonPath('message', 'XLSX export requires the PHP zip extension.');
    }
}
