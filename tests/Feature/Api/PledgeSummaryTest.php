<?php

namespace Tests\Feature\Api;

use App\Models\Celebration;
use App\Models\Event;
use App\Models\Pledge;
use App\Models\PledgeFulfillment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pledge summary semantics (event / account / celebration roll-ups).
 *
 * These tests pin down the documented meaning of the aggregates:
 *
 *  - Counts (`total_pledges`, `counts`, `status_counts`) are currency-agnostic
 *    and DO include cancelled pledges.
 *  - Pledged / fulfilled / outstanding money figures EXCLUDE cancelled pledges
 *    and are always separated per currency (never summed across currencies).
 *  - A celebration roll-up is a single aggregation of its member events'
 *    pledges: every pledge is counted exactly once, and neither an outside
 *    event nor another account's event leaks in.
 */
class PledgeSummaryTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create();
    }

    private function event(User $owner, array $attributes = []): Event
    {
        return Event::factory()->create(array_merge([
            'user_id' => $owner->id,
            'event_start' => now()->addDays(7),
            'settings' => [
                'capabilities' => ['contributions' => true],
            ],
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
            'pledged_amount_minor' => 10000,
        ], $attributes));
    }

    private function cancel(Pledge $pledge): Pledge
    {
        $pledge->forceFill(['status' => Pledge::STATUS_CANCELLED])->save();

        return $pledge->fresh();
    }

    // ------------------------------------------------------------- cancellation

    public function test_cancelled_pledges_are_counted_but_excluded_from_money_totals(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);

        $active = $this->pledge($event, ['pledged_amount_minor' => 10000]);
        PledgeFulfillment::factory()->create(['pledge_id' => $active->id, 'amount_minor' => 4000, 'method' => 'cash']);

        // A cancelled pledge that was fulfilled *before* it was cancelled: its
        // money must not reappear anywhere in the totals.
        $cancelled = $this->pledge($event, ['pledged_amount_minor' => 2000]);
        PledgeFulfillment::factory()->create(['pledge_id' => $cancelled->id, 'amount_minor' => 500, 'method' => 'cash']);
        $this->cancel($cancelled);

        $response = $this->actingAs($owner)
            ->getJson(route('api.events.pledges.summary', $event))
            ->assertOk();

        // Counted (documented) ...
        $response
            ->assertJsonPath('total_pledges', 2)
            ->assertJsonPath('counts.cancelled', 1)
            ->assertJsonPath('status_counts.cancelled', 1)
            ->assertJsonPath('status_counts.active', 1)
            ->assertJsonPath('counts.partially_fulfilled', 1);

        // ... but excluded from every money figure:
        $usd = collect($response->json('currencies'))->firstWhere('currency', 'USD');
        $this->assertSame(1, $usd['pledges']);
        $this->assertSame(10000, $usd['pledged_minor']);
        $this->assertSame(4000, $usd['fulfilled_minor']);
        $this->assertSame(6000, $usd['outstanding_minor']);

        // The cancelled pledge's fulfillment is excluded from the method
        // breakdown too (methods carry reported money, so they follow the same
        // cancelled-exclusion rule as the per-currency totals).
        $methods = collect($response->json('by_method'))->keyBy('method');
        $this->assertSame(1, $methods['cash']['entries']);
        $this->assertSame(4000, $methods['cash']['amount_minor']);
    }

    public function test_by_method_amounts_are_separated_per_currency(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);

        $usd = $this->pledge($event, ['currency' => 'USD', 'pledged_amount_minor' => 10000]);
        PledgeFulfillment::factory()->create(['pledge_id' => $usd->id, 'amount_minor' => 1000, 'method' => 'cash']);

        $tzs = $this->pledge($event, ['currency' => 'TZS', 'pledged_amount_minor' => 20000]);
        PledgeFulfillment::factory()->create(['pledge_id' => $tzs->id, 'amount_minor' => 5000, 'method' => 'cash']);

        $byCurrency = collect(
            $this->actingAs($owner)
                ->getJson(route('api.events.pledges.summary', $event))
                ->assertOk()
                ->json('by_method')
        )->keyBy('currency');

        // Same method, different currencies → separate rows; amounts never summed.
        $this->assertCount(2, $byCurrency);
        $this->assertSame('cash', $byCurrency['USD']['method']);
        $this->assertSame(1000, $byCurrency['USD']['amount_minor']);
        $this->assertSame(5000, $byCurrency['TZS']['amount_minor']);
    }

    public function test_celebration_rollup_excludes_member_events_with_contributions_disabled(): void
    {
        $owner = $this->owner();
        $celebration = Celebration::factory()->create(['user_id' => $owner->id]);

        $enabled = $this->event($owner, ['celebration_id' => $celebration->id]);
        $disabled = $this->event($owner, [
            'celebration_id' => $celebration->id,
            'settings' => ['capabilities' => ['contributions' => false]],
        ]);

        $this->pledge($enabled, ['currency' => 'USD', 'pledged_amount_minor' => 10000]);
        $this->pledge($disabled, ['currency' => 'USD', 'pledged_amount_minor' => 999999]);

        $response = $this->actingAs($owner)
            ->getJson(route('api.reports.celebrations.pledges', $celebration))
            ->assertOk();

        $response->assertJsonPath('event_count', 1)->assertJsonPath('total_pledges', 1);

        $currencies = collect($response->json('currencies'))->keyBy('currency');
        $this->assertSame(10000, $currencies['USD']['pledged_minor']);
    }

    public function test_account_report_excludes_events_with_contributions_disabled(): void
    {
        $owner = $this->owner();
        $enabled = $this->event($owner);
        $disabled = $this->event($owner, ['settings' => ['capabilities' => ['contributions' => false]]]);

        $this->pledge($enabled, ['currency' => 'USD', 'pledged_amount_minor' => 10000]);
        $this->pledge($disabled, ['currency' => 'USD', 'pledged_amount_minor' => 999999]);

        $response = $this->actingAs($owner)->getJson(route('api.reports.pledges'))->assertOk();

        $response->assertJsonPath('total_pledges', 1);

        $currencies = collect($response->json('currencies'))->keyBy('currency');
        $this->assertSame(10000, $currencies['USD']['pledged_minor']);
    }

    public function test_an_event_with_only_cancelled_pledges_has_no_money_rows(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);

        $pledge = $this->pledge($event, ['pledged_amount_minor' => 5000]);
        PledgeFulfillment::factory()->create(['pledge_id' => $pledge->id, 'amount_minor' => 2000]);
        $this->cancel($pledge);

        $response = $this->actingAs($owner)
            ->getJson(route('api.events.pledges.summary', $event))
            ->assertOk();

        $response
            ->assertJsonPath('total_pledges', 1)
            ->assertJsonPath('counts.cancelled', 1)
            ->assertJsonPath('status_counts.cancelled', 1);

        $this->assertSame([], $response->json('currencies'));
        $this->assertSame([], $response->json('by_method'));
    }

    // ------------------------------------------------------- per-currency totals

    public function test_money_totals_are_separated_per_currency_and_never_combined(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);

        $usd = $this->pledge($event, ['currency' => 'USD', 'pledged_amount_minor' => 10000]);
        PledgeFulfillment::factory()->create(['pledge_id' => $usd->id, 'amount_minor' => 1000, 'method' => 'cash']);

        $tzs = $this->pledge($event, ['currency' => 'TZS', 'pledged_amount_minor' => 20000]);
        PledgeFulfillment::factory()->create(['pledge_id' => $tzs->id, 'amount_minor' => 5000, 'method' => 'cash']);

        $currencies = collect(
            $this->actingAs($owner)
                ->getJson(route('api.events.pledges.summary', $event))
                ->assertOk()
                ->json('currencies')
        )->keyBy('currency');

        $this->assertCount(2, $currencies);

        // Each currency keeps its own figures; USD and TZS are never added.
        $this->assertSame(10000, $currencies['USD']['pledged_minor']);
        $this->assertSame(1000, $currencies['USD']['fulfilled_minor']);
        $this->assertSame(9000, $currencies['USD']['outstanding_minor']);

        $this->assertSame(20000, $currencies['TZS']['pledged_minor']);
        $this->assertSame(5000, $currencies['TZS']['fulfilled_minor']);
        $this->assertSame(15000, $currencies['TZS']['outstanding_minor']);
    }

    // ------------------------------------------------------------- celebration

    public function test_celebration_rollup_equals_the_sum_of_member_event_pledges_exactly_once(): void
    {
        $owner = $this->owner();
        $other = $this->owner();

        $celebration = Celebration::factory()->create(['user_id' => $owner->id]);

        $memberA = $this->event($owner, ['celebration_id' => $celebration->id]);
        $memberB = $this->event($owner, ['celebration_id' => $celebration->id]);
        $outside = $this->event($owner);

        // memberA: USD 10 000 (2 500 fulfilled), TZS 5 000, plus a cancelled USD pledge.
        $usdA = $this->pledge($memberA, ['currency' => 'USD', 'pledged_amount_minor' => 10000]);
        PledgeFulfillment::factory()->create(['pledge_id' => $usdA->id, 'amount_minor' => 2500, 'method' => 'cash']);
        $this->pledge($memberA, ['currency' => 'TZS', 'pledged_amount_minor' => 5000]);

        $cancelledA = $this->pledge($memberA, ['currency' => 'USD', 'pledged_amount_minor' => 999]);
        PledgeFulfillment::factory()->create(['pledge_id' => $cancelledA->id, 'amount_minor' => 100, 'method' => 'cash']);
        $this->cancel($cancelledA);

        // memberB: USD 20 000.
        $this->pledge($memberB, ['currency' => 'USD', 'pledged_amount_minor' => 20000]);

        // Must never leak into the celebration:
        $this->pledge($outside, ['currency' => 'USD', 'pledged_amount_minor' => 777777]);

        // An event belonging to another account but pointed at this celebration
        // must be filtered out by the ownership guard.
        $foreign = $this->event($other, ['celebration_id' => $celebration->id]);
        $this->pledge($foreign, ['currency' => 'USD', 'pledged_amount_minor' => 888888]);

        $response = $this->actingAs($owner)
            ->getJson(route('api.reports.celebrations.pledges', $celebration))
            ->assertOk();

        $response
            ->assertJsonPath('event_count', 2)
            ->assertJsonPath('total_pledges', 4)
            ->assertJsonPath('status_counts.active', 3)
            ->assertJsonPath('status_counts.cancelled', 1)
            ->assertJsonPath('counts.cancelled', 1);

        $currencies = collect($response->json('currencies'))->keyBy('currency');
        $this->assertCount(2, $currencies);
        $this->assertSame(30000, $currencies['USD']['pledged_minor']);
        $this->assertSame(2500, $currencies['USD']['fulfilled_minor']);
        $this->assertSame(27500, $currencies['USD']['outstanding_minor']);
        $this->assertSame(5000, $currencies['TZS']['pledged_minor']);

        // Exactly once: the roll-up equals the sum of the member-event slices,
        // and contains no outside/foreign event or extra pledge.
        $events = collect($response->json('events'))->keyBy('event_id');
        $this->assertCount(2, $events);
        $this->assertTrue($events->has($memberA->id));
        $this->assertTrue($events->has($memberB->id));
        $this->assertFalse($events->has($outside->id));
        $this->assertFalse($events->has($foreign->id));

        foreach (['USD', 'TZS'] as $currency) {
            $rollup = $currencies[$currency]['pledged_minor'];
            $sumOfSlices = $events->sum(function (array $slice) use ($currency) {
                $row = collect($slice['currencies'])->firstWhere('currency', $currency);

                return (int) ($row['pledged_minor'] ?? 0);
            });

            $this->assertSame($rollup, $sumOfSlices, "Celebration roll-up for {$currency} must equal the sum of its event slices.");
        }

        // The cancelled pledge's 999 / 100 must not appear anywhere.
        $this->assertNotSame(30999, $currencies['USD']['pledged_minor']);
        $this->assertNotSame(2600, $currencies['USD']['fulfilled_minor']);
    }

    public function test_celebration_rollup_is_empty_without_member_pledges(): void
    {
        $owner = $this->owner();
        $celebration = Celebration::factory()->create(['user_id' => $owner->id]);
        $this->event($owner, ['celebration_id' => $celebration->id]);

        $response = $this->actingAs($owner)
            ->getJson(route('api.reports.celebrations.pledges', $celebration))
            ->assertOk();

        $response
            ->assertJsonPath('event_count', 1)
            ->assertJsonPath('total_pledges', 0)
            ->assertJsonPath('currencies', []);
    }
}
