<?php

namespace Tests\Feature\Api;

use App\Models\Attendance;
use App\Models\Celebration;
use App\Models\Event;
use App\Models\Guest;
use App\Models\InvitationDelivery;
use App\Models\User;
use App\Support\PageSize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bounded pagination for the remaining operational lists (events, celebrations,
 * celebration member events, attendance, failed deliveries, reports/events) and
 * the `admin-read` limiter on their operator read routes.
 */
class OperationalPaginationTest extends TestCase
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
        ], $attributes));
    }

    private function checkInEvent(User $owner): Event
    {
        return $this->event($owner, ['settings' => ['capabilities' => ['check_in' => true]]]);
    }

    private function seedEvents(User $owner, int $count, string $prefix = 'Event'): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->event($owner, [
                'title' => $prefix.' '.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'event_start' => now()->addDays($i),
            ]);
        }
    }

    // 1 — events index default / bounds / invalid.
    public function test_events_index_is_paginated_and_bounded(): void
    {
        $owner = $this->owner();
        $this->seedEvents($owner, 25);

        $default = $this->actingAs($owner)->getJson(route('api.events.index'))->assertOk();
        $this->assertSame(PageSize::DEFAULT, $default->json('per_page'));
        $this->assertSame(25, $default->json('total'));
        $this->assertCount(PageSize::DEFAULT, $default->json('data'));
        $default->assertJsonPath('last_page', 2);

        $this->assertSame(50, $this->actingAs($owner)->getJson(route('api.events.index', ['per_page' => 50]))->json('per_page'));
        $this->assertSame(PageSize::MAX, $this->actingAs($owner)->getJson(route('api.events.index', ['per_page' => 500]))->json('per_page'));

        foreach (['0', '-1', 'abc', ''] as $invalid) {
            $this->assertSame(
                PageSize::DEFAULT,
                $this->actingAs($owner)->getJson(route('api.events.index', ['per_page' => $invalid]))->json('per_page'),
            );
        }
    }

    public function test_events_index_search_and_isolation(): void
    {
        $owner = $this->owner();
        $intruder = $this->owner();
        $this->seedEvents($owner, 30, 'Match');
        $this->seedEvents($owner, 5, 'Other');
        $this->seedEvents($intruder, 4, 'Match');

        $page1 = $this->actingAs($owner)
            ->getJson(route('api.events.index', ['search' => 'Match', 'per_page' => 10]))
            ->assertOk();

        $this->assertSame(30, $page1->json('total'));
        $page1->assertJsonPath('last_page', 3);

        $page3 = $this->actingAs($owner)
            ->getJson(route('api.events.index', ['search' => 'Match', 'per_page' => 10, 'page' => 3]))
            ->assertOk();
        foreach ($page3->json('data') as $row) {
            $this->assertStringStartsWith('Match', $row['title']);
        }
    }

    // 2 — celebrations index.
    public function test_celebrations_index_is_paginated_and_bounded(): void
    {
        $owner = $this->owner();
        Celebration::factory()->count(25)->create(['user_id' => $owner->id]);

        $default = $this->actingAs($owner)->getJson(route('api.celebrations.index'))->assertOk();
        $this->assertSame(PageSize::DEFAULT, $default->json('per_page'));
        $this->assertSame(25, $default->json('total'));
        $default->assertJsonPath('last_page', 2);

        $this->assertSame(PageSize::MAX, $this->actingAs($owner)->getJson(route('api.celebrations.index', ['per_page' => 500]))->json('per_page'));

        $other = $this->owner();
        Celebration::factory()->count(3)->create(['user_id' => $other->id]);
        $this->assertSame(25, $this->actingAs($owner)->getJson(route('api.celebrations.index'))->json('total'));
    }

    // 3 — celebration member events are paginated.
    public function test_celebration_show_paginates_member_events(): void
    {
        $owner = $this->owner();
        $celebration = Celebration::factory()->create(['user_id' => $owner->id]);
        for ($i = 1; $i <= 5; $i++) {
            $this->event($owner, ['title' => "Member {$i}", 'celebration_id' => $celebration->id, 'event_start' => now()->addDays($i)]);
        }

        $response = $this->actingAs($owner)
            ->getJson(route('api.celebrations.show', ['celebration' => $celebration->id, 'per_page' => 2]))
            ->assertOk();

        $this->assertSame(5, $response->json('events_meta.total'));
        $this->assertSame(3, $response->json('events_meta.last_page'));
        $this->assertCount(2, $response->json('events'));

        $page3 = $this->actingAs($owner)
            ->getJson(route('api.celebrations.show', ['celebration' => $celebration->id, 'per_page' => 2, 'page' => 3]))
            ->assertOk();
        $this->assertCount(1, $page3->json('events'));

        $intruder = $this->owner();
        $this->actingAs($intruder)->getJson(route('api.celebrations.show', $celebration))->assertStatus(403);
    }

    // 4 — attendance list pagination + counts + status filter.
    public function test_attendance_index_is_paginated_with_counts(): void
    {
        $owner = $this->owner();
        $event = $this->checkInEvent($owner);

        for ($i = 0; $i < 25; $i++) {
            $guest = Guest::factory()->create(['event_id' => $event->id]);
            Attendance::create([
                'event_id' => $event->id,
                'guest_id' => $guest->id,
                'checked_in_at' => now(),
                'checked_in_by' => $owner->id,
                'method' => 'manual',
                'party_size' => 1,
                'status' => Attendance::STATUS_VALID,
                'active_key' => $guest->id,
            ]);
        }

        $response = $this->actingAs($owner)->getJson(route('api.events.attendances.index', $event))->assertOk();

        $this->assertSame(PageSize::DEFAULT, $response->json('per_page'));
        $this->assertSame(25, $response->json('total'));
        $this->assertCount(PageSize::DEFAULT, $response->json('attendances'));
        $this->assertSame(25, $response->json('checked_in'));

        $bounded = $this->actingAs($owner)->getJson(route('api.events.attendances.index', ['event' => $event->id, 'per_page' => 500]))->assertOk();
        $this->assertSame(PageSize::MAX, $bounded->json('per_page'));

        $filtered = $this->actingAs($owner)
            ->getJson(route('api.events.attendances.index', ['event' => $event->id, 'status' => 'reversed']))
            ->assertOk();
        $this->assertSame(0, $filtered->json('total'));
    }

    // 5 — failed deliveries list is bounded.
    public function test_failed_deliveries_are_bounded(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        for ($i = 0; $i < 25; $i++) {
            $guest = Guest::factory()->create(['event_id' => $event->id]);
            InvitationDelivery::create([
                'guest_id' => $guest->id,
                'kind' => 'invitation',
                'channel' => 'mail',
                'status' => 'failed',
                'error' => 'smtp',
            ]);
        }

        $response = $this->actingAs($owner)
            ->getJson(route('api.events.deliveries.failed', ['event' => $event->id, 'per_page' => 10]))
            ->assertOk();

        $this->assertSame(10, $response->json('per_page'));
        $this->assertSame(25, $response->json('total'));
        $this->assertCount(10, $response->json('data'));
    }

    // 6 — reports/events is bounded and scoped.
    public function test_reports_events_is_paginated_and_scoped(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $this->seedEvents($owner, 25);
        $this->seedEvents($other, 5);

        $response = $this->actingAs($owner)->getJson(route('api.reports.events'))->assertOk();

        $this->assertSame(PageSize::DEFAULT, $response->json('per_page'));
        $this->assertSame(25, $response->json('total_events'));
        $this->assertCount(PageSize::DEFAULT, $response->json('events'));
        $response->assertJsonPath('last_page', 2);

        $this->assertSame(0, $this->actingAs($this->owner())->getJson(route('api.reports.events'))->json('total_events'));
    }

    // 7 — read throttles on the operational operator read routes.
    public function test_operational_read_routes_use_the_admin_read_limiter(): void
    {
        $router = app('router');

        $routes = [
            'api.events.index',
            'api.events.show',
            'api.celebrations.index',
            'api.celebrations.show',
            'api.reports.events',
            'api.events.deliveries',
            'api.events.deliveries.failed',
            'api.events.communication.channels',
            'api.events.invitations.preview',
            'api.delivery-lab.index',
            'api.events.attendances.index',
        ];

        foreach ($routes as $name) {
            $this->assertContains(
                'throttle:admin-read',
                $router->getRoutes()->getByName($name)->gatherMiddleware(),
                "{$name} should use the admin-read limiter",
            );
        }
    }
}
