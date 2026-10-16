<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\Guest;
use App\Models\User;
use App\Support\PageSize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bounded, validated pagination for the event guest list (GuestController::index)
 * and the `admin-read` limiter on the read route.
 */
class GuestPaginationTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create();
    }

    private function event(User $owner): Event
    {
        return Event::factory()->create(['user_id' => $owner->id, 'event_start' => now()->addDays(7)]);
    }

    private function seedGuests(Event $event, string $prefix, int $count, string $status = 'pending'): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $n = str_pad((string) $i, 3, '0', STR_PAD_LEFT);
            Guest::factory()->create([
                'event_id' => $event->id,
                'name' => "{$prefix} {$n}",
                'email' => strtolower($prefix)."{$n}@example.test",
                'status' => $status,
            ]);
        }
    }

    private function index(Event $event, User $user, array $params = [])
    {
        return $this->actingAs($user)->getJson(route('api.events.guests.index', array_merge(['event' => $event->id], $params)));
    }

    public function test_index_defaults_to_the_default_page_size(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $this->seedGuests($event, 'Guest', 25);

        $response = $this->index($event, $owner)->assertOk();

        $this->assertSame(PageSize::DEFAULT, $response->json('per_page'));
        $this->assertSame(25, $response->json('total'));
        $this->assertCount(PageSize::DEFAULT, $response->json('data'));
        $response->assertJsonPath('current_page', 1)->assertJsonPath('last_page', 2);
        $this->assertSame(25, $response->json('counts.total'));
        $this->assertSame(25, $response->json('counts.pending'));
    }

    public function test_index_accepts_and_bounds_per_page(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $this->seedGuests($event, 'Guest', 25);

        $this->assertSame(50, $this->index($event, $owner, ['per_page' => 50])->json('per_page'));
        $this->assertSame(PageSize::MAX, $this->index($event, $owner, ['per_page' => 500])->json('per_page'));
    }

    public function test_index_falls_back_to_default_for_invalid_per_page(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $this->seedGuests($event, 'Guest', 3);

        foreach (['0', '-5', 'abc', ''] as $invalid) {
            $this->assertSame(
                PageSize::DEFAULT,
                $this->index($event, $owner, ['per_page' => $invalid])->json('per_page'),
                "per_page={$invalid} should fall back to the default",
            );
        }
    }

    public function test_index_clamps_invalid_page_to_the_first_page(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $this->seedGuests($event, 'Guest', 25);

        foreach (['-3', '0', 'abc'] as $invalid) {
            $this->index($event, $owner, ['page' => $invalid])->assertOk()->assertJsonPath('current_page', 1);
        }
    }

    public function test_status_filter_is_applied_before_pagination(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $this->seedGuests($event, 'Pend', 30, 'pending');
        $this->seedGuests($event, 'Accept', 5, 'accepted');

        $response = $this->index($event, $owner, ['status' => 'pending', 'per_page' => 10])->assertOk();

        $this->assertSame(30, $response->json('total'));
        $response->assertJsonPath('last_page', 3);

        $page3 = $this->index($event, $owner, ['status' => 'pending', 'per_page' => 10, 'page' => 3])->assertOk();
        $this->assertCount(10, $page3->json('data'));
        foreach ($page3->json('data') as $row) {
            $this->assertSame('pending', $row['status']);
        }
    }

    public function test_search_filter_is_applied_before_pagination(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $this->seedGuests($event, 'Match', 30);
        $this->seedGuests($event, 'Other', 5);

        $response = $this->index($event, $owner, ['search' => 'Match', 'per_page' => 10])->assertOk();

        $this->assertSame(30, $response->json('total'));
        $response->assertJsonPath('last_page', 3);

        $page3 = $this->index($event, $owner, ['search' => 'Match', 'per_page' => 10, 'page' => 3])->assertOk();
        foreach ($page3->json('data') as $row) {
            $this->assertStringStartsWith('Match', $row['name']);
        }
    }

    public function test_ordering_is_stable_and_scoped_to_the_event(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $other = $this->event($owner);

        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = Guest::factory()->create(['event_id' => $event->id, 'name' => 'Same Name'])->id;
        }
        Guest::factory()->create(['event_id' => $other->id, 'name' => 'Same Name']);

        $expected = $ids;
        sort($expected);

        $seen = [];
        foreach ([1, 2, 3] as $page) {
            $response = $this->index($event, $owner, ['per_page' => 2, 'page' => $page])->assertOk();
            foreach ($response->json('data') as $row) {
                $seen[] = $row['id'];
            }
        }

        $this->assertSame($expected, $seen);
        $this->assertSame(5, $this->index($event, $owner)->json('total'));
    }

    public function test_guest_read_route_uses_the_admin_read_limiter(): void
    {
        $middleware = app('router')->getRoutes()->getByName('api.events.guests.index')->gatherMiddleware();

        $this->assertContains('throttle:admin-read', $middleware);
    }
}
