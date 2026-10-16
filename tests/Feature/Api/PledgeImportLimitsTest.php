<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\Guest;
use App\Models\Pledge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Row-limit enforcement and bounded preview pagination for the pledge import.
 *
 * The configured row limit applies at upload, preview, confirm and error-report
 * time, so it cannot be bypassed by calling a different endpoint or replaying a
 * confirmation. The preview validates the whole file but returns one bounded
 * page of row objects.
 */
class PledgeImportLimitsTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['pledger_name', 'email', 'amount', 'currency'];

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
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $headers
     */
    private function csv(array $rows, array $headers = self::HEADERS): string
    {
        $lines = [implode(',', $headers)];

        foreach ($rows as $row) {
            $cells = [];
            foreach ($headers as $header) {
                $cells[] = (string) ($row[$header] ?? '');
            }
            $lines[] = implode(',', $cells);
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validRows(int $count, string $prefix = 'Person'): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'pledger_name' => "{$prefix} {$i}",
                'email' => strtolower($prefix)."{$i}@example.test",
                'amount' => 1000,
                'currency' => 'TZS',
            ];
        }

        return $rows;
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

    // 1 — a workbook exactly at the limit is accepted.
    public function test_workbook_at_the_row_limit_is_accepted(): void
    {
        config(['invitations.max_pledge_import_rows' => 5]);

        $owner = $this->owner();
        $event = $this->event($owner);

        $upload = $this->upload($event, $owner, $this->csv($this->validRows(5)))->assertStatus(201);

        $this->assertSame(5, $upload->json('row_count'));
        $this->assertSame(5, $upload->json('max_rows'));
        $this->assertDatabaseCount('pledge_import_batches', 1);
    }

    // 2 — a workbook over the limit is rejected before any batch/pledge is created.
    public function test_workbook_over_the_row_limit_is_rejected_at_upload(): void
    {
        config(['invitations.max_pledge_import_rows' => 5]);

        $owner = $this->owner();
        $event = $this->event($owner);

        $response = $this->upload($event, $owner, $this->csv($this->validRows(6)))
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertStringContainsString('5', (string) $response->json('errors.file.0'));

        $this->assertDatabaseCount('pledge_import_batches', 0);
        $this->assertDatabaseCount('pledges', 0);
    }

    // 3 — blank rows and the header row are not counted as data rows.
    public function test_blank_rows_and_header_are_excluded_from_the_count(): void
    {
        config(['invitations.max_pledge_import_rows' => 5]);

        $owner = $this->owner();
        $event = $this->event($owner);

        // header + 3 data rows, with blank lines interspersed.
        $content = implode("\n", [
            implode(',', self::HEADERS),
            'A, a@example.test,1000,TZS',
            ',,,,',
            'B, b@example.test,1000,TZS',
            '',
            'C, c@example.test,1000,TZS',
        ]);

        $upload = $this->upload($event, $owner, $content)->assertStatus(201);

        $this->assertSame(3, $upload->json('row_count'));

        // A header-only file counts zero data rows and is still accepted.
        $headerOnly = $this->upload($event, $owner, implode(',', self::HEADERS))->assertStatus(201);
        $this->assertSame(0, $headerOnly->json('row_count'));
    }

    // 4 — the limit is enforced at preview, confirm and error-report time too.
    public function test_limit_is_enforced_on_preview_confirm_and_errors(): void
    {
        config(['invitations.max_pledge_import_rows' => 5]);

        $owner = $this->owner();
        $event = $this->event($owner);
        $batchId = $this->batchId($event, $owner, $this->csv($this->validRows(5)));

        // A batch uploaded while within the limit is rejected once the limit drops.
        config(['invitations.max_pledge_import_rows' => 2]);

        $this->actingAs($owner)
            ->postJson(route('api.events.pledges.import.preview', [$event, $batchId]), [])
            ->assertStatus(422)->assertJsonValidationErrors('file');

        $this->actingAs($owner)
            ->postJson(route('api.events.pledges.import.confirm', [$event, $batchId]), [])
            ->assertStatus(422)->assertJsonValidationErrors('file');

        $this->actingAs($owner)
            ->getJson(route('api.events.pledges.import.errors', [$event, $batchId]))
            ->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('pledges', 0);
    }

    // 5 — aggregate counts stay exact while only one bounded page is returned.
    public function test_preview_counts_are_exact_while_rows_are_paginated(): void
    {
        config([
            'invitations.max_pledge_import_rows' => 100,
            'invitations.pledge_import_preview_page_size' => 10,
            'invitations.pledge_import_preview_max_page_size' => 100,
        ]);

        $owner = $this->owner();
        $event = $this->event($owner);
        $batchId = $this->batchId($event, $owner, $this->csv($this->validRows(25)));

        $page1 = $this->actingAs($owner)
            ->postJson(route('api.events.pledges.import.preview', [$event, $batchId]), ['page' => 1])
            ->assertOk();

        $this->assertSame(25, $page1->json('counts.total_rows'));
        $this->assertSame(25, $page1->json('preview.total_rows'));
        $this->assertSame(3, $page1->json('preview.last_page'));
        $this->assertCount(10, $page1->json('rows'));

        $page3 = $this->actingAs($owner)
            ->postJson(route('api.events.pledges.import.preview', [$event, $batchId]), ['page' => 3])
            ->assertOk();

        $this->assertCount(5, $page3->json('rows'));

        // per_page is bounded and cannot inflate the payload.
        $bounded = $this->actingAs($owner)
            ->postJson(route('api.events.pledges.import.preview', [$event, $batchId]), ['page' => 1, 'per_page' => 9999])
            ->assertOk();
        $this->assertSame(100, $bounded->json('preview.per_page'));
    }

    // 6 — preview ordering across pages is stable and complete (no dropped rows).
    public function test_preview_rows_are_stable_and_complete_across_pages(): void
    {
        config([
            'invitations.max_pledge_import_rows' => 100,
            'invitations.pledge_import_preview_page_size' => 10,
        ]);

        $owner = $this->owner();
        $event = $this->event($owner);
        $batchId = $this->batchId($event, $owner, $this->csv($this->validRows(25)));

        $seen = [];
        for ($page = 1; $page <= 3; $page++) {
            $response = $this->actingAs($owner)
                ->postJson(route('api.events.pledges.import.preview', [$event, $batchId]), ['page' => $page])
                ->assertOk();

            foreach ($response->json('rows') as $row) {
                $seen[] = $row['row'];
            }
        }

        $this->assertSame(range(1, 25), $seen);
    }

    // 7 — ambiguous rows are reported across the whole file, not only the page.
    public function test_ambiguous_rows_are_reported_across_preview_pages(): void
    {
        config([
            'invitations.max_pledge_import_rows' => 100,
            'invitations.pledge_import_preview_page_size' => 5,
        ]);

        $owner = $this->owner();
        $event = $this->event($owner);
        $guest = Guest::factory()->create(['event_id' => $event->id, 'email' => 'amb@example.test']);
        Pledge::factory()->create(['event_id' => $event->id, 'user_id' => $owner->id, 'guest_id' => $guest->id, 'currency' => 'TZS', 'pledged_amount_minor' => 1000]);
        Pledge::factory()->create(['event_id' => $event->id, 'user_id' => $owner->id, 'guest_id' => $guest->id, 'currency' => 'TZS', 'pledged_amount_minor' => 2000]);

        // 10 valid rows, then the ambiguous row at position 11.
        $rows = $this->validRows(10);
        $rows[] = ['pledger_name' => 'Amb', 'email' => 'amb@example.test', 'amount' => 5000, 'currency' => 'TZS'];

        $batchId = $this->batchId($event, $owner, $this->csv($rows));

        $page1 = $this->actingAs($owner)
            ->postJson(route('api.events.pledges.import.preview', [$event, $batchId]), ['page' => 1])
            ->assertOk();

        // The ambiguous row is on page 3, yet it is surfaced on page 1.
        $this->assertSame([11], $page1->json('ambiguous_rows'));
        $this->assertSame(1, $page1->json('counts.ambiguous'));

        foreach ($page1->json('rows') as $row) {
            $this->assertNotSame('ambiguous', $row['action']);
        }
    }

    // 10 — a large (within-limit) error report is generated without losing rows.
    public function test_error_report_includes_every_problem_row(): void
    {
        config(['invitations.max_pledge_import_rows' => 20]);

        $owner = $this->owner();
        $event = $this->event($owner);

        // 6 invalid rows (blank pledger name) + 1 valid row.
        $rows = [];
        for ($i = 1; $i <= 6; $i++) {
            $rows[] = ['pledger_name' => '', 'email' => "bad{$i}@example.test", 'amount' => 1000, 'currency' => 'TZS'];
        }
        $rows[] = ['pledger_name' => 'Valid', 'email' => 'valid@example.test', 'amount' => 1000, 'currency' => 'TZS'];

        $batchId = $this->batchId($event, $owner, $this->csv($rows));

        $response = $this->actingAs($owner)
            ->get(route('api.events.pledges.import.errors', ['event' => $event->id, 'batch' => $batchId, 'format' => 'csv']))
            ->assertOk()->assertDownload();

        $contents = (string) file_get_contents($response->baseResponse->getFile()->getPathname());

        // header line + 6 problem rows.
        $lines = array_values(array_filter(explode("\n", trim($contents)), static fn ($line) => trim($line) !== ''));
        $this->assertCount(7, $lines);
    }

    // 12 — the row limit respects authorization (owner/event isolation).
    public function test_over_limit_upload_respects_authorization(): void
    {
        config(['invitations.max_pledge_import_rows' => 1]);

        $owner = $this->owner();
        $intruder = $this->owner();
        $event = $this->event($owner);

        $this->upload($event, $intruder, $this->csv($this->validRows(5)))
            ->assertStatus(403);

        $this->assertDatabaseCount('pledge_import_batches', 0);
    }
}
