<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\Pledge;
use App\Models\User;
use App\Services\PledgeImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The template-driven import contract: the official Invitation Studio template
 * and the importer share one canonical field definition, the template is
 * recognised automatically on upload, and required-column problems are reported
 * at the file level while row-value problems stay at the row level.
 */
class PledgeImportContractTest extends TestCase
{
    use RefreshDatabase;

    /** Canonical headers, in the exact order `PledgeImporter::FIELDS` declares. */
    private const HEADERS = [
        'pledger_name', 'email', 'phone', 'amount', 'contributed_amount',
        'currency', 'method', 'notes', 'reference', 'guest_id', 'contact_id', 'received_on',
    ];

    private function owner(): User
    {
        return User::factory()->create();
    }

    private function event(User $owner, string $currency = 'TZS'): Event
    {
        return Event::factory()->create([
            'user_id' => $owner->id,
            'event_start' => now()->addDays(7),
            'settings' => ['capabilities' => ['contributions' => true], 'currency' => $currency],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $headers
     */
    private function csv(array $rows, array $headers = self::HEADERS): string
    {
        $lines = [implode(',', $headers)];

        foreach ($rows as $row) {
            $cells = [];
            foreach ($headers as $header) {
                $cells[] = str_replace([',', '"', "\n", "\r"], [' ', '', ' ', ''], (string) ($row[$header] ?? ''));
            }
            $lines[] = implode(',', $cells);
        }

        return implode("\n", $lines);
    }

    private function upload(Event $event, User $user, string $content, string $name = 'pledges.csv')
    {
        return $this->actingAs($user)
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('api.events.pledges.import.upload', ['event' => $event->id]), [
                'file' => UploadedFile::fake()->createWithContent($name, $content),
            ]);
    }

    private function batchId(Event $event, User $user, string $content): int
    {
        return (int) $this->upload($event, $user, $content)->assertStatus(201)->json('batch.id');
    }

    // 1 + 13 — the template and importer share one canonical, ordinary-amount contract.

    public function test_canonical_contract_and_template_headers_agree(): void
    {
        $importer = app(PledgeImporter::class);

        $headers = array_map(static fn (array $field): string => $field['header'], PledgeImporter::fields());

        $this->assertSame(self::HEADERS, $headers);
        $this->assertSame($headers, array_keys($importer->templateRow()));

        // The official template never asks the operator for minor units.
        foreach ($headers as $header) {
            $this->assertStringNotContainsString('minor', $header);
        }

        $this->assertContains('amount', $headers);
        $this->assertContains('contributed_amount', $headers);

        // Required fields are exactly pledger name and pledged amount.
        $required = array_values(array_map(
            static fn (array $field): string => $field['key'],
            array_filter(PledgeImporter::fields(), static fn (array $field): bool => $field['required']),
        ));
        $this->assertSame(['pledger_name', 'pledged_amount'], $required);
    }

    // 2 — the downloaded official template uploads and previews with no manual mapping.

    public function test_official_template_uploads_and_previews_without_manual_mapping(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The PHP zip extension is required for XLSX.');
        }

        $owner = $this->owner();
        $event = $this->event($owner, 'USD');

        $download = $this->actingAs($owner)
            ->get(route('api.events.pledges.import.template', ['event' => $event->id]))
            ->assertOk();

        $copy = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        copy($download->baseResponse->getFile()->getPathname(), $copy);

        try {
            $upload = $this->actingAs($owner)
                ->withHeaders(['Accept' => 'application/json'])
                ->post(route('api.events.pledges.import.upload', ['event' => $event->id]), [
                    'file' => new UploadedFile($copy, 'template.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
                ])->assertStatus(201);

            // Recognition maps every canonical header automatically; nothing missing.
            $upload->assertJsonPath('recognition.missing_required', []);
            $this->assertSame('amount', $upload->json('mapping.pledged_amount'));

            $this->assertTrue(
                \Illuminate\Support\Facades\Storage::exists('pledge-imports/'.$upload->json('batch.id').'.xlsx'),
                'the uploaded source is stored at its deterministic batch path',
            );

            // Preview with no body at all (uses the automatic stored mapping).
            $preview = $this->actingAs($owner)->postJson(
                route('api.events.pledges.import.preview', [$event, $upload->json('batch.id')]),
                [],
            )->assertOk();

            $this->assertSame(0, $preview->json('counts.failed'), 'rows: '.json_encode($preview->json('rows')));
            $this->assertGreaterThanOrEqual(1, $preview->json('counts.created'));
        } finally {
            @unlink($copy);
        }
    }

    // 3 — header aliases (case/whitespace/punctuation normalised) are recognised.

    public function test_header_aliases_are_recognized(): void
    {
        $mapping = app(PledgeImporter::class)->suggestMapping([
            'Pledger Name', 'E-mail', 'Mobile', 'amount', 'Currency Code',
        ]);

        $this->assertSame('Pledger Name', $mapping['pledger_name']);
        $this->assertSame('E-mail', $mapping['pledger_email']);
        $this->assertSame('Mobile', $mapping['pledger_phone']);
        $this->assertSame('amount', $mapping['pledged_amount']);
        $this->assertSame('Currency Code', $mapping['currency']);
    }

    // 14 — a `pledge_date` column is reported and never reinterpreted.

    public function test_pledge_date_is_reported_as_unsupported_and_never_mapped(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);

        $headers = ['pledger_name', 'amount', 'currency', 'pledge_date'];
        $content = $this->csv([
            ['pledger_name' => 'Amina', 'amount' => 500000, 'currency' => 'TZS', 'pledge_date' => '2026-01-01'],
        ], $headers);

        $upload = $this->upload($event, $owner, $content)->assertStatus(201);

        $notices = $upload->json('recognition.notices');
        $this->assertNotEmpty($notices);
        $this->assertSame('pledge_date', $notices[0]['header']);
        $this->assertStringContainsString('not imported', $notices[0]['reason']);

        // Never mapped to any supported field (especially not received_on).
        $this->assertNotContains('pledge_date', array_values((array) $upload->json('mapping')));
        $this->assertNotSame('pledge_date', $upload->json('mapping.received_on'));
    }

    // 4 — a missing required COLUMN is a file-level error, not a per-row error.

    public function test_missing_required_column_produces_a_file_level_error(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);

        // No amount column at all.
        $content = $this->csv([
            ['pledger_name' => 'Amina', 'currency' => 'TZS'],
        ], ['pledger_name', 'currency']);

        $upload = $this->upload($event, $owner, $content)->assertStatus(201);

        $this->assertNotEmpty($upload->json('recognition.missing_required'));
        $this->assertSame('amount', $upload->json('recognition.missing_required.0.header'));

        $this->actingAs($owner)->postJson(
            route('api.events.pledges.import.preview', [$event, $upload->json('batch.id')]),
            [],
        )->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('pledges', 0);
    }

    // 5 — a recognised header with a bad value stays a row-level error.

    public function test_bad_required_values_produce_row_level_errors(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);

        $content = $this->csv([
            ['pledger_name' => '', 'amount' => 500000, 'currency' => 'TZS'],
            ['pledger_name' => 'Amina', 'amount' => '', 'currency' => 'TZS'],
            ['pledger_name' => 'Brian', 'amount' => -5, 'currency' => 'TZS'],
            ['pledger_name' => 'Cara', 'amount' => 250000, 'currency' => 'TZS'],
        ]);

        $preview = $this->actingAs($owner)->postJson(
            route('api.events.pledges.import.preview', [$event, $this->batchId($event, $owner, $content)]),
            [],
        )->assertOk();

        $this->assertSame(3, $preview->json('counts.failed'));
        $this->assertSame(1, $preview->json('counts.created'));
        $this->assertArrayHasKey('pledger_name', $preview->json('rows.0.errors'));
        $this->assertArrayHasKey('pledged_amount', $preview->json('rows.1.errors'));
        $this->assertArrayHasKey('pledged_amount', $preview->json('rows.2.errors'));
    }

    // 6 — ordinary amounts convert by currency precision; negatives are rejected.

    public function test_ordinary_amounts_convert_by_currency_precision(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner, 'USD');

        $content = $this->csv([
            ['pledger_name' => 'Whole', 'amount' => '500000', 'currency' => 'USD'],
            ['pledger_name' => 'Decimal', 'amount' => '100.00', 'currency' => 'USD'],
            ['pledger_name' => 'Fraction', 'amount' => '1234.56', 'currency' => 'USD'],
            ['pledger_name' => 'ZeroDecimal', 'amount' => '500', 'currency' => 'JPY'],
        ]);

        $batchId = $this->batchId($event, $owner, $content);

        $this->actingAs($owner)->postJson(route('api.events.pledges.import.confirm', [$event, $batchId]), [])
            ->assertOk()
            ->assertJsonPath('counts.created', 4);

        $amount = static fn (string $name): int => (int) Pledge::where('event_id', $event->id)->where('pledger_name', $name)->firstOrFail()->pledged_amount_minor;

        $this->assertSame(50000000, $amount('Whole'));
        $this->assertSame(10000, $amount('Decimal'));
        $this->assertSame(123456, $amount('Fraction'));
        $this->assertSame(500, $amount('ZeroDecimal')); // JPY has no minor unit
    }

    // 7 + end-to-end — canonical headers: upload → preview → confirm.

    public function test_end_to_end_canonical_headers_upload_preview_confirm(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner, 'TZS');

        $content = $this->csv([
            ['pledger_name' => 'Amina', 'email' => 'amina@example.test', 'phone' => '+255700000001', 'amount' => 500000, 'currency' => 'TZS', 'method' => 'cash', 'notes' => 'first', 'reference' => 'REF-1'],
            ['pledger_name' => 'Brian', 'email' => 'brian@example.test', 'amount' => 100, 'currency' => 'USD', 'contributed_amount' => 40],
        ]);

        $batchId = $this->batchId($event, $owner, $content);

        // Preview (no manual mapping) never writes.
        $preview = $this->actingAs($owner)
            ->postJson(route('api.events.pledges.import.preview', [$event, $batchId]), [])
            ->assertOk();

        $this->assertSame(2, $preview->json('counts.created'));
        $this->assertSame(0, $preview->json('counts.failed'));
        $this->assertDatabaseCount('pledges', 0);
        $this->assertDatabaseCount('pledge_fulfillments', 0);

        // Confirmation applies.
        $this->actingAs($owner)
            ->postJson(route('api.events.pledges.import.confirm', [$event, $batchId]), [])
            ->assertOk()
            ->assertJsonPath('counts.created', 2);

        $amina = Pledge::where('event_id', $event->id)->where('pledger_name', 'Amina')->firstOrFail();
        $this->assertSame('TZS', $amina->currency);
        $this->assertSame(50000000, (int) $amina->pledged_amount_minor);

        $brian = Pledge::where('event_id', $event->id)->where('pledger_name', 'Brian')->firstOrFail();
        $this->assertSame(10000, (int) $brian->pledged_amount_minor);

        // The optional contributed_amount became an appended fulfillment (40.00 USD).
        $this->assertDatabaseHas('pledge_fulfillments', ['pledge_id' => $brian->id, 'amount_minor' => 4000]);
    }

    // 8 + 9 — duplicates are skipped and re-import is idempotent.

    public function test_duplicates_are_skipped_and_reimport_is_idempotent(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner, 'TZS');

        $content = $this->csv([
            ['pledger_name' => 'Amina', 'email' => 'amina@example.test', 'amount' => 500000, 'currency' => 'TZS'],
            ['pledger_name' => 'Amina', 'email' => 'amina@example.test', 'amount' => 500000, 'currency' => 'TZS'],
        ]);

        $first = $this->batchId($event, $owner, $content);
        $this->actingAs($owner)->postJson(route('api.events.pledges.import.confirm', [$event, $first]), [])
            ->assertOk()
            ->assertJsonPath('counts.created', 1)
            ->assertJsonPath('counts.skipped', 1);

        $this->assertDatabaseCount('pledges', 1);

        $second = $this->batchId($event, $owner, $content);
        $this->actingAs($owner)->postJson(route('api.events.pledges.import.confirm', [$event, $second]), [])
            ->assertOk()
            ->assertJsonPath('counts.created', 0)
            ->assertJsonPath('counts.skipped', 2);

        $this->assertDatabaseCount('pledges', 1);
    }

    // 10 — preview never writes pledges or fulfillments.

    public function test_preview_never_writes_records(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner, 'TZS');

        $content = $this->csv([
            ['pledger_name' => 'Amina', 'email' => 'amina@example.test', 'amount' => 500000, 'currency' => 'TZS', 'contributed_amount' => 10000],
        ]);

        $this->actingAs($owner)->postJson(
            route('api.events.pledges.import.preview', [$event, $this->batchId($event, $owner, $content)]),
            [],
        )->assertOk();

        $this->assertDatabaseCount('pledges', 0);
        $this->assertDatabaseCount('pledge_fulfillments', 0);
    }

    // 15 — legacy minor-unit headers remain supported.

    public function test_legacy_minor_unit_headers_remain_supported(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner, 'USD');

        $content = $this->csv([
            ['pledger_name' => 'Legacy', 'currency' => 'USD', 'pledged_amount_minor' => 12345],
        ], ['pledger_name', 'currency', 'pledged_amount_minor']);

        $upload = $this->upload($event, $owner, $content)->assertStatus(201);
        $this->assertSame('pledged_amount_minor', $upload->json('mapping.pledged_amount_minor'));

        // The legacy column is flagged as legacy in recognition.
        $recognized = collect($upload->json('recognition.recognized'))->firstWhere('key', 'pledged_amount_minor');
        $this->assertNotNull($recognized);
        $this->assertTrue((bool) $recognized['legacy']);

        // No file-level error: the legacy column satisfies the amount requirement.
        $upload->assertJsonPath('recognition.missing_required', []);

        $this->actingAs($owner)->postJson(
            route('api.events.pledges.import.confirm', [$event, $upload->json('batch.id')]),
            [],
        )->assertOk()->assertJsonPath('counts.created', 1);

        // Minor units are stored verbatim, never rescaled.
        $this->assertSame(12345, (int) Pledge::where('event_id', $event->id)->firstOrFail()->pledged_amount_minor);
    }

    // 11 — a stale/outdated mapping cannot import.

    public function test_outdated_mapping_cannot_confirm(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner, 'TZS');

        $content = $this->csv([
            ['pledger_name' => 'Amina', 'amount' => 500000, 'currency' => 'TZS'],
        ]);

        $batchId = $this->batchId($event, $owner, $content);

        $this->actingAs($owner)->postJson(route('api.events.pledges.import.preview', [$event, $batchId]), [])->assertOk();

        // Confirm with a mapping that no longer maps the required amount.
        $this->actingAs($owner)->postJson(
            route('api.events.pledges.import.confirm', [$event, $batchId]),
            ['mapping' => ['pledger_name' => 'pledger_name', 'currency' => 'currency']],
        )->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('pledges', 0);
    }

    // The upload response exposes recognition + a read-only sample.

    public function test_upload_reports_recognition_and_a_sample(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner, 'TZS');

        $content = $this->csv([
            ['pledger_name' => 'Amina', 'amount' => 500000, 'currency' => 'TZS'],
            ['pledger_name' => 'Brian', 'amount' => 250000, 'currency' => 'TZS'],
        ]);

        $upload = $this->upload($event, $owner, $content)->assertStatus(201);

        $upload->assertJsonPath('row_count', 2);
        $upload->assertJsonPath('recognition.missing_required', []);

        $recognizedKeys = array_column($upload->json('recognition.recognized'), 'key');
        $this->assertContains('pledger_name', $recognizedKeys);
        $this->assertContains('pledged_amount', $recognizedKeys);

        $sample = $upload->json('sample');
        $this->assertCount(2, $sample);
        $this->assertSame('Amina', $sample[0]['values']['pledger_name']);
        $this->assertSame('500000', (string) $sample[0]['values']['pledged_amount']);

        $this->assertDatabaseCount('pledges', 0);
    }
}
