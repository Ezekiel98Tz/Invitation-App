<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\Pledge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Behavioral equivalence for the optimized fallback matcher.
 *
 * The matcher previously scanned every event pledge per row. It now uses a
 * pre-indexed lookup (normalized email/phone) but must produce exactly the same
 * decisions: update/skip on a single email/phone + currency + amount match, no
 * match when currency or amount differs, and `ambiguous` when several pledges
 * match.
 */
class PledgeImportMatchTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['pledger_name', 'email', 'phone', 'amount', 'currency'];

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

    private function pledge(Event $event, array $attributes = []): Pledge
    {
        return Pledge::factory()->create(array_merge([
            'user_id' => $event->user_id,
            'event_id' => $event->id,
            'guest_id' => null,
            'contact_id' => null,
            'currency' => 'TZS',
            // 500000 minor units == ordinary amount 5000 in TZS (2 decimals).
            'pledged_amount_minor' => 500000,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function uploadRow(Event $event, User $user, array $row)
    {
        $lines = [implode(',', self::HEADERS)];
        $cells = [];
        foreach (self::HEADERS as $header) {
            $cells[] = (string) ($row[$header] ?? '');
        }
        $lines[] = implode(',', $cells);

        $upload = $this->actingAs($user)
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('api.events.pledges.import.upload', ['event' => $event->id]), [
                'file' => UploadedFile::fake()->createWithContent('pledges.csv', implode("\n", $lines)),
            ])
            ->assertStatus(201);

        return (int) $upload->json('batch.id');
    }

    private function preview(Event $event, User $user, int $batchId, string $mode = 'create_only')
    {
        return $this->actingAs($user)->postJson(
            route('api.events.pledges.import.preview', [$event->id, $batchId]),
            ['mode' => $mode],
        )->assertOk();
    }

    public function test_single_email_match_is_proposed_as_an_update(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $existing = $this->pledge($event, ['pledger_email' => 'match@example.test', 'pledger_phone' => null]);

        $batchId = $this->uploadRow($event, $owner, [
            'pledger_name' => 'Match', 'email' => 'match@example.test', 'phone' => '',
            'amount' => 5000, 'currency' => 'TZS',
        ]);

        $preview = $this->preview($event, $owner, $batchId, 'update_matched');

        $preview->assertJsonPath('rows.0.action', 'update');
        $preview->assertJsonPath('rows.0.pledge_id', $existing->id);
        $this->assertSame(0, $preview->json('counts.ambiguous'));

        // create_only skips a matched pledge (never duplicates, never updates).
        $this->actingAs($owner)->postJson(
            route('api.events.pledges.import.confirm', [$event->id, $batchId]),
            ['mode' => 'create_only'],
        )->assertOk()->assertJsonPath('counts.skipped', 1);

        $this->assertSame(1, Pledge::where('event_id', $event->id)->count());
    }

    public function test_single_phone_match_is_proposed_as_an_update(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $existing = $this->pledge($event, ['pledger_email' => null, 'pledger_phone' => '+255700000001']);

        $batchId = $this->uploadRow($event, $owner, [
            'pledger_name' => 'Phone', 'email' => '', 'phone' => '+255 700 000 001',
            'amount' => 5000, 'currency' => 'TZS',
        ]);

        $this->preview($event, $owner, $batchId, 'update_matched')
            ->assertJsonPath('rows.0.action', 'update')
            ->assertJsonPath('rows.0.pledge_id', $existing->id);
    }

    public function test_different_amount_or_currency_does_not_match(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $this->pledge($event, ['pledger_email' => 'nomatch@example.test', 'pledger_phone' => null, 'pledged_amount_minor' => 500000, 'currency' => 'TZS']);

        // Same email, different amount -> no match.
        $batchA = $this->uploadRow($event, $owner, [
            'pledger_name' => 'A', 'email' => 'nomatch@example.test', 'phone' => '',
            'amount' => 9999, 'currency' => 'TZS',
        ]);
        $this->preview($event, $owner, $batchA)->assertJsonPath('rows.0.action', 'create');

        // Same email + amount, different currency -> no match.
        $batchB = $this->uploadRow($event, $owner, [
            'pledger_name' => 'B', 'email' => 'nomatch@example.test', 'phone' => '',
            'amount' => 5000, 'currency' => 'USD',
        ]);
        $this->preview($event, $owner, $batchB)->assertJsonPath('rows.0.action', 'create');
    }

    public function test_multiple_matches_are_ambiguous_and_never_merged_without_a_decision(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $this->pledge($event, ['pledger_email' => 'amb@example.test', 'pledger_phone' => null, 'pledged_amount_minor' => 500000, 'currency' => 'TZS']);
        $this->pledge($event, ['pledger_email' => 'amb@example.test', 'pledger_phone' => null, 'pledged_amount_minor' => 500000, 'currency' => 'TZS']);

        $batchId = $this->uploadRow($event, $owner, [
            'pledger_name' => 'Amb', 'email' => 'amb@example.test', 'phone' => '',
            'amount' => 5000, 'currency' => 'TZS',
        ]);

        $this->preview($event, $owner, $batchId)
            ->assertJsonPath('rows.0.action', 'ambiguous')
            ->assertJsonPath('counts.ambiguous', 1);

        // Without an explicit decision the ambiguous row is skipped, not merged.
        $this->actingAs($owner)->postJson(
            route('api.events.pledges.import.confirm', [$event->id, $batchId]),
            ['mode' => 'update_matched'],
        )->assertOk()->assertJsonPath('counts.updated', 0)->assertJsonPath('counts.skipped', 1);

        $this->assertSame(2, Pledge::where('event_id', $event->id)->count());
    }
}
