<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\Pledge;
use App\Models\PledgeImportBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Regression fixture mirroring the real client workbook
 * `Invitation_Studio_Pledge_Test_Import.xlsx` (sheet "Pledge Import"):
 *
 *   pledger_name | email | phone | amount | currency | method |
 *   pledge_date | notes | reference
 *
 * It contains ordinary (non-minor-unit) amounts, a mixed USD/TZS row an
 * intentional within-file duplicate and an intentionally malformed row. This
 * pins that such a workbook imports without requiring minor units, email or a
 * currency column, and that the malformed/duplicate rows are handled safely.
 */
class PledgeImportRealWorkbookTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const HEADERS = [
        'pledger_name', 'email', 'phone', 'amount', 'currency', 'method', 'pledge_date', 'notes', 'reference',
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
            'settings' => ['capabilities' => ['contributions' => true], 'currency' => 'TZS'],
        ]);
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function csv(array $rows): string
    {
        $escape = static fn ($v): string => str_contains((string) $v, ',') ? '"'.$v.'"' : (string) $v;
        $lines = [implode(',', self::HEADERS)];

        foreach ($rows as $row) {
            $lines[] = implode(',', array_map($escape, $row));
        }

        return implode("\n", $lines);
    }

    /** @return list<list<mixed>> */
    private function rows(): array
    {
        return [
            ['Amina Joseph', 'amina@example.test', '+255700000001', '500000', 'TZS', 'cash', '2026-01-01', 'pledge', 'TEST-001'],
            ['Daniel Mwinyi', 'daniel@example.test', '+255700000002', '250000', 'TZS', 'bank_transfer', '2026-01-02', 'later', 'TEST-002'],
            ['Brian Charles', 'brian@example.test', '+255700000003', '100', 'USD', 'cash', '2026-01-03', 'usd', 'TEST-004'],
            // Intentional duplicate of Amina (same phone/amount/currency) → skip.
            ['Amina Joseph', 'amina@example.test', '+255700000001', '500000', 'TZS', 'cash', '2026-01-01', 'dup', 'TEST-001'],
            // Intentionally malformed: blank name + invalid email + negative amount.
            ['', 'invalid-email', '', '-1000', 'TZS', 'cash', 'not-a-date', 'invalid', 'TEST-INVALID'],
            // Phone-only identity (no email), supported.
            ['Grace Nyerere', '', '+255700000004', '200000', 'TZS', 'mobile_money', '2026-01-04', 'phone only', 'TEST-008'],
        ];
    }

    private function upload(Event $event, User $user): int
    {
        return (int) $this->actingAs($user)
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('api.events.pledges.import.upload', ['event' => $event->id]), [
                'file' => UploadedFile::fake()->createWithContent('pledges.csv', $this->csv($this->rows())),
            ])
            ->assertStatus(201)
            ->json('batch.id');
    }

    public function test_real_workbook_headers_and_suggested_mapping(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);

        $response = $this->actingAs($owner)
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('api.events.pledges.import.upload', ['event' => $event->id]), [
                'file' => UploadedFile::fake()->createWithContent('pledges.csv', $this->csv($this->rows())),
            ])
            ->assertStatus(201);

        $this->assertSame(self::HEADERS, $response->json('batch.columns'));

        $mapping = $response->json('batch.mapping') ?? $response->json('suggested_mapping');
        $this->assertSame('pledger_name', $mapping['pledger_name']);
        $this->assertSame('email', $mapping['pledger_email']);
        $this->assertSame('phone', $mapping['pledger_phone']);
        $this->assertSame('amount', $mapping['pledged_amount']);
        $this->assertSame('currency', $mapping['currency']);
        $this->assertSame('method', $mapping['method']);
        $this->assertSame('notes', $mapping['note']);
        $this->assertSame('reference', $mapping['reference']);
    }

    public function test_browser_contract_preview_reproduces_the_reported_counts(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);

        $upload = $this->actingAs($owner)
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('api.events.pledges.import.upload', ['event' => $event->id]), [
                'file' => UploadedFile::fake()->createWithContent('pledges.csv', $this->csv($this->rows())),
            ])
            ->assertStatus(201);

        $batchId = (int) $upload->json('batch.id');
        $mapping = (array) $upload->json('batch.mapping');

        // The upload must suggest the ordinary-amount destination for `amount`.
        $this->assertSame('amount', $mapping['pledged_amount'] ?? null);
        $this->assertSame('pledger_name', $mapping['pledger_name'] ?? null);

        // Exactly what the browser posts: its mapping state (the suggestions).
        $preview = $this->actingAs($owner)->postJson(
            route('api.events.pledges.import.preview', [$event, $batchId]),
            ['mapping' => $mapping, 'mode' => 'create_only'],
        )->assertOk();

        $preview->assertJsonPath('counts.created', 4)
            ->assertJsonPath('counts.skipped', 1)
            ->assertJsonPath('counts.failed', 1)
            ->assertJsonPath('counts.total_rows', 6);

        // No row reports a missing/invalid pledged_amount except the malformed row (row 5).
        foreach ($preview->json('rows') as $row) {
            $errors = (array) ($row['errors'] ?? []);

            if ((int) $row['row'] === 5) {
                continue;
            }

            $this->assertArrayNotHasKey('pledged_amount', $errors, 'row '.$row['row']);
            $this->assertArrayNotHasKey('pledged_amount_minor', $errors, 'row '.$row['row']);
            $this->assertArrayNotHasKey('pledger_name', $errors, 'row '.$row['row']);
        }

        // An empty mapping still falls back to the stored upload suggestion.
        $fallback = $this->actingAs($owner)->postJson(
            route('api.events.pledges.import.preview', [$event, $batchId]),
            ['mode' => 'create_only'],
        )->assertOk();

        $fallback->assertJsonPath('counts.created', 4)
            ->assertJsonPath('counts.failed', 1);

        // An EXPLICITLY empty mapping array (e.g. the frontend state lost its
        // suggestions) must also fall back — this was the defect that produced
        // "missing pledger_name / pledged_amount" for every row.
        $emptyMapping = $this->actingAs($owner)->postJson(
            route('api.events.pledges.import.preview', [$event, $batchId]),
            ['mapping' => [], 'mode' => 'create_only'],
        )->assertOk();

        $emptyMapping->assertJsonPath('counts.created', 4)
            ->assertJsonPath('counts.failed', 1);
    }

    public function test_pledge_date_is_intentionally_not_auto_mapped(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);

        $response = $this->actingAs($owner)
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('api.events.pledges.import.upload', ['event' => $event->id]), [
                'file' => UploadedFile::fake()->createWithContent('pledges.csv', $this->csv($this->rows())),
            ])
            ->assertStatus(201);

        $mapping = $response->json('batch.mapping') ?? [];

        // The pledge model has no pledge-date field, so this column is left
        // unmapped (mappable later as an optional contribution date if desired).
        $this->assertNotContains('pledge_date', array_values($mapping));
    }

    public function test_real_workbook_previews_and_confirms_without_minor_units_or_email(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $batchId = $this->upload($event, $owner);

        $mapping = [
            'pledger_name' => 'pledger_name',
            'pledger_email' => 'email',
            'pledger_phone' => 'phone',
            'pledged_amount' => 'amount',
            'currency' => 'currency',
            'method' => 'method',
            'note' => 'notes',
            'reference' => 'reference',
        ];

        // 6 rows → 4 valid creates, 1 skipped duplicate, 1 failed (malformed).
        $this->actingAs($owner)->postJson(
            route('api.events.pledges.import.confirm', [$event, $batchId]),
            ['mapping' => $mapping, 'mode' => 'create_only'],
        )->assertOk()
            ->assertJsonPath('counts.created', 4)
            ->assertJsonPath('counts.skipped', 1)
            ->assertJsonPath('counts.failed', 1);

        // Ordinary TZS 500,000 → 50,000,000 minor units; USD 100 → 10,000.
        $amina = Pledge::where('event_id', $event->id)->where('pledger_name', 'Amina Joseph')->firstOrFail();
        $this->assertSame('TZS', $amina->currency);
        $this->assertSame(50000000, (int) $amina->pledged_amount_minor);

        $brian = Pledge::where('event_id', $event->id)->where('pledger_name', 'Brian Charles')->firstOrFail();
        $this->assertSame('USD', $brian->currency);
        $this->assertSame(10000, (int) $brian->pledged_amount_minor);

        // Phone-only email-less row imported; the malformed row did not.
        $this->assertDatabaseHas('pledges', ['event_id' => $event->id, 'pledger_name' => 'Grace Nyerere']);
        $this->assertSame(4, Pledge::where('event_id', $event->id)->count());

        // Duplicate of Amina was not created twice.
        $this->assertSame(1, Pledge::where('event_id', $event->id)->where('pledger_name', 'Amina Joseph')->count());

        // Currencies remain separate in the event summary.
        $currencies = collect(
            $this->actingAs($owner)->getJson(route('api.events.pledges.summary', $event))->assertOk()->json('currencies')
        )->keyBy('currency');
        $this->assertSame(95000000, $currencies['TZS']['pledged_minor']);
        $this->assertSame(10000, $currencies['USD']['pledged_minor']);
    }

    /**
     * The real workbook stores `amount` as EXCEL NUMERIC cells (integers and
     * floats), not text — verified against
     * `Invitation_Studio_Pledge_Test_Import.xlsx`. This pins that such cells
     * reach the canonical `pledged_amount` destination and are converted to
     * minor units by currency precision.
     */
    public function test_numeric_excel_amount_cells_map_to_pledged_amount(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);

        $columns = self::HEADERS;

        // Numeric cell values exactly as the XLSX reader yields them (int/float).
        $rows = [
            array_combine($columns, ['Numeric Pledger', 'num@example.test', '+255700000009', 500000, 'TZS', 'cash', '2026-01-01', 'num', 'NUM-001']),
            array_combine($columns, ['Second Pledger', 'two@example.test', '+255700000010', 1234.56, 'USD', 'bank_transfer', '2026-01-02', 'num', 'NUM-002']),
        ];

        $importer = app(\App\Services\PledgeImporter::class);
        $mapping = $importer->suggestMapping($columns);

        $this->assertSame('amount', $mapping['pledged_amount'] ?? null, 'numeric `amount` column must suggest pledged_amount');

        $result = $importer->preview($event, $rows, $mapping, [], 'create_only', null);

        $this->assertSame(2, $result['counts']['created'], 'rows: '.json_encode($result['rows']));
        $this->assertSame(0, $result['counts']['failed'], 'rows: '.json_encode($result['rows']));

        foreach ($result['rows'] as $row) {
            $errors = (array) ($row['errors'] ?? []);
            $values = (array) ($row['values'] ?? []);

            $this->assertArrayNotHasKey('pledged_amount', $errors, 'row '.$row['row']);

            // Integer 500000 TZS → 50,000,000 minor units; float 1234.56 USD → 123,456.
            if (($values['pledger_name'] ?? null) === 'Numeric Pledger') {
                $this->assertSame(50000000, (int) ($values['pledged_amount_minor'] ?? -1));
            }

            if (($values['pledger_name'] ?? null) === 'Second Pledger') {
                $this->assertSame(123456, (int) ($values['pledged_amount_minor'] ?? -1));
            }
        }
    }

    /**
     * When a required destination is not mapped, the problem is a missing COLUMN,
     * so it is reported once as a file-level error — never as a missing value on
     * every row.
     */
    public function test_missing_required_mapping_produces_a_file_level_error(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $batchId = $this->upload($event, $owner);

        // Name mapped, amount deliberately NOT mapped.
        $this->actingAs($owner)->postJson(
            route('api.events.pledges.import.preview', [$event, $batchId]),
            ['mapping' => ['pledger_name' => 'pledger_name', 'currency' => 'currency'], 'mode' => 'create_only'],
        )->assertStatus(422)->assertJsonValidationErrors('file');

        // Amount mapped, name deliberately NOT mapped.
        $this->actingAs($owner)->postJson(
            route('api.events.pledges.import.preview', [$event, $batchId]),
            ['mapping' => ['pledged_amount' => 'amount', 'currency' => 'currency'], 'mode' => 'create_only'],
        )->assertStatus(422)->assertJsonValidationErrors('file');
    }

    /**
     * Confirmation re-runs the mapping/validation from the current state; it
     * never imports rows that a stale preview had considered importable.
     */
    public function test_confirm_re_validates_and_never_imports_from_a_stale_preview(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $batchId = $this->upload($event, $owner);

        $this->actingAs($owner)->postJson(
            route('api.events.pledges.import.preview', [$event, $batchId]),
            [
                'mapping' => [
                    'pledger_name' => 'pledger_name',
                    'pledger_email' => 'email',
                    'pledger_phone' => 'phone',
                    'pledged_amount' => 'amount',
                    'currency' => 'currency',
                ],
                'mode' => 'create_only',
            ],
        )->assertOk()->assertJsonPath('counts.created', 4);

        // Confirm with an OUTDATED/incomplete mapping (amount no longer mapped).
        $status = $this->actingAs($owner)->postJson(
            route('api.events.pledges.import.confirm', [$event, $batchId]),
            ['mapping' => ['pledger_name' => 'pledger_name', 'currency' => 'currency'], 'mode' => 'create_only'],
        )->getStatusCode();

        $this->assertContains($status, [200, 422]);
        $this->assertSame(0, Pledge::where('event_id', $event->id)->count(), 'a stale preview must not import pledges');
    }
}
