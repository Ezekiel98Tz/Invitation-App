<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\Pledge;
use App\Models\PledgeFulfillment;
use App\Models\User;
use App\Support\PageSize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bounded pagination for the event pledge list, plus parity between the list's
 * SQL-aggregated balances and the detail endpoint's relation-computed balances.
 */
class PledgePaginationTest extends TestCase
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

    private function seedPledges(Event $event, int $count, string $prefix = 'Pledger'): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->pledge($event, [
                'pledger_name' => $prefix.' '.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'pledger_email' => strtolower($prefix)."{$i}@example.test",
            ]);
        }
    }

    private function index(Event $event, User $user, array $params = [])
    {
        return $this->actingAs($user)->getJson(
            route('api.events.pledges.index', array_merge(['event' => $event->id], $params)),
        );
    }

    public function test_index_defaults_to_the_default_page_size(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $this->seedPledges($event, 25);

        $response = $this->index($event, $owner)->assertOk();

        $this->assertSame(PageSize::DEFAULT, $response->json('per_page'));
        $this->assertSame(25, $response->json('total'));
        $this->assertCount(PageSize::DEFAULT, $response->json('data'));
        $response->assertJsonPath('last_page', 2);
    }

    public function test_index_accepts_and_bounds_per_page(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $this->seedPledges($event, 25);

        $this->assertSame(50, $this->index($event, $owner, ['per_page' => 50])->json('per_page'));
        $this->assertSame(PageSize::MAX, $this->index($event, $owner, ['per_page' => 500])->json('per_page'));

        foreach (['0', '-2', 'abc', ''] as $invalid) {
            $this->assertSame(PageSize::DEFAULT, $this->index($event, $owner, ['per_page' => $invalid])->json('per_page'));
        }
    }

    public function test_index_clamps_invalid_page(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $this->seedPledges($event, 25);

        $this->index($event, $owner, ['page' => 'abc'])->assertOk()->assertJsonPath('current_page', 1);
    }

    public function test_status_filter_is_applied_before_pagination(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $this->seedPledges($event, 15);
        for ($i = 0; $i < 5; $i++) {
            $this->pledge($event, ['status' => Pledge::STATUS_CANCELLED]);
        }

        $response = $this->index($event, $owner, ['status' => 'cancelled', 'per_page' => 2])->assertOk();

        $this->assertSame(5, $response->json('total'));
        $response->assertJsonPath('last_page', 3);
        foreach ($response->json('data') as $row) {
            $this->assertSame('cancelled', $row['status']);
        }
    }

    public function test_search_is_applied_before_pagination(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $this->seedPledges($event, 30, 'Match');
        $this->seedPledges($event, 5, 'Other');

        $response = $this->index($event, $owner, ['search' => 'Match', 'per_page' => 10])->assertOk();

        $this->assertSame(30, $response->json('total'));
        foreach ($this->index($event, $owner, ['search' => 'Match', 'per_page' => 10, 'page' => 3])->json('data') as $row) {
            $this->assertStringStartsWith('Match', $row['pledger_name']);
        }
    }

    public function test_index_is_scoped_to_the_event(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $other = $this->event($owner);
        $this->seedPledges($event, 5, 'Mine');
        $this->seedPledges($other, 5, 'Theirs');

        $response = $this->index($event, $owner)->assertOk();

        $this->assertSame(5, $response->json('total'));
        foreach ($response->json('data') as $row) {
            $this->assertStringStartsWith('Mine', $row['pledger_name']);
        }
    }

    public function test_list_balances_match_the_detail_endpoint(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);

        // partial
        $partial = $this->pledge($event);
        PledgeFulfillment::factory()->create(['pledge_id' => $partial->id, 'amount_minor' => 4000]);

        // over-fulfilled
        $over = $this->pledge($event);
        PledgeFulfillment::factory()->create(['pledge_id' => $over->id, 'amount_minor' => 12000]);

        // recorded then reversed => net zero
        $reversed = $this->pledge($event);
        $original = PledgeFulfillment::factory()->create(['pledge_id' => $reversed->id, 'amount_minor' => 5000]);
        PledgeFulfillment::factory()->create([
            'pledge_id' => $reversed->id,
            'amount_minor' => 5000,
            'status' => PledgeFulfillment::STATUS_REVERSED,
            'reverses_fulfillment_id' => $original->id,
        ]);

        // cancelled with a recorded fulfillment
        $cancelled = $this->pledge($event, ['status' => Pledge::STATUS_CANCELLED]);
        PledgeFulfillment::factory()->create(['pledge_id' => $cancelled->id, 'amount_minor' => 3000]);

        $list = collect($this->index($event, $owner, ['per_page' => 100])->assertOk()->json('data'))->keyBy('id');

        $expected = [
            $partial->id => ['fulfilled_minor' => 4000, 'outstanding_minor' => 6000, 'derived_status' => 'partially_fulfilled'],
            $over->id => ['fulfilled_minor' => 12000, 'outstanding_minor' => 0, 'derived_status' => 'over_fulfilled'],
            $reversed->id => ['fulfilled_minor' => 0, 'outstanding_minor' => 10000, 'derived_status' => 'open'],
            $cancelled->id => ['fulfilled_minor' => 3000, 'outstanding_minor' => 7000, 'derived_status' => 'cancelled'],
        ];

        foreach ($expected as $id => $balances) {
            $row = $list[$id];

            $this->assertSame($balances['fulfilled_minor'], $row['fulfilled_minor'], "pledge {$id} fulfilled");
            $this->assertSame($balances['outstanding_minor'], $row['outstanding_minor'], "pledge {$id} outstanding");
            $this->assertSame($balances['derived_status'], $row['derived_status'], "pledge {$id} status");

            // The list aggregates in SQL, so it must NOT ship the full history...
            $this->assertNull($row['fulfillments'], "pledge {$id} list fulfillments should be null");
            $this->assertNotNull($row['fulfillments_count'], "pledge {$id} list count should be populated");

            // ...while the detail endpoint (relation loaded) agrees exactly.
            $detail = $this->actingAs($owner)
                ->getJson(route('api.events.pledges.show', [$event->id, $id]))
                ->assertOk();

            $this->assertSame($row['fulfilled_minor'], $detail->json('fulfilled_minor'));
            $this->assertSame($row['outstanding_minor'], $detail->json('outstanding_minor'));
            $this->assertSame($row['derived_status'], $detail->json('derived_status'));
            $this->assertIsArray($detail->json('fulfillments'));
        }
    }

    public function test_pledge_read_routes_use_the_admin_read_limiter(): void
    {
        $router = app('router');

        foreach (['api.events.pledges.index', 'api.events.pledges.show'] as $name) {
            $this->assertContains(
                'throttle:admin-read',
                $router->getRoutes()->getByName($name)->gatherMiddleware(),
                "{$name} should use the admin-read limiter",
            );
        }
    }
}
