<?php

namespace Tests\Feature\Api;

use App\Models\Contact;
use App\Models\Event;
use App\Models\Guest;
use App\Models\User;
use App\Support\PageSize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bounded, validated pagination for the Contacts collection, plus the shared
 * `admin-read` throttle on the contact read routes.
 *
 * Page numbers are clamped to >= 1 by Laravel's paginator resolver
 * (Illuminate\Pagination\PaginationState); `per_page` is clamped by
 * App\Support\PageSize.
 */
class ContactPaginationTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function contact(User $owner, array $attributes = []): Contact
    {
        return Contact::factory()->create(array_merge([
            'user_id' => $owner->id,
            'email' => null,
            'normalized_email' => null,
            'phone' => null,
            'normalized_phone' => null,
        ], $attributes));
    }

    private function seedNames(User $owner, string $prefix, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $n = str_pad((string) $i, 3, '0', STR_PAD_LEFT);
            $this->contact($owner, [
                'name' => "{$prefix} {$n}",
                'email' => "{$prefix}{$n}@example.test",
                'normalized_email' => strtolower("{$prefix}{$n}@example.test"),
            ]);
        }
    }

    public function test_index_defaults_to_the_default_page_size(): void
    {
        $owner = $this->owner();
        $this->seedNames($owner, 'Person', 25);

        $response = $this->actingAs($owner)->getJson(route('api.contacts.index'))->assertOk();

        $this->assertSame(PageSize::DEFAULT, $response->json('per_page'));
        $this->assertSame(25, $response->json('total'));
        $this->assertCount(PageSize::DEFAULT, $response->json('data'));
        $response->assertJsonPath('current_page', 1)->assertJsonPath('last_page', 2);
    }

    public function test_index_accepts_a_bounded_per_page(): void
    {
        $owner = $this->owner();
        $this->seedNames($owner, 'Person', 25);

        $response = $this->actingAs($owner)
            ->getJson(route('api.contacts.index', ['per_page' => 50]))
            ->assertOk();

        $this->assertSame(50, $response->json('per_page'));
        $this->assertCount(25, $response->json('data'));
        $response->assertJsonPath('last_page', 1);
    }

    public function test_index_clamps_per_page_to_the_maximum(): void
    {
        $owner = $this->owner();
        $this->seedNames($owner, 'Person', 3);

        $response = $this->actingAs($owner)
            ->getJson(route('api.contacts.index', ['per_page' => 500]))
            ->assertOk();

        $this->assertSame(PageSize::MAX, $response->json('per_page'));
        $this->assertCount(3, $response->json('data'));
    }

    public function test_index_falls_back_to_the_default_for_invalid_per_page(): void
    {
        $owner = $this->owner();
        $this->seedNames($owner, 'Person', 3);

        foreach (['0', '-5', 'abc', ''] as $invalid) {
            $response = $this->actingAs($owner)
                ->getJson(route('api.contacts.index', ['per_page' => $invalid]))
                ->assertOk();

            $this->assertSame(
                PageSize::DEFAULT,
                $response->json('per_page'),
                "per_page={$invalid} should fall back to the default",
            );
        }
    }

    public function test_index_clamps_invalid_page_to_the_first_page(): void
    {
        $owner = $this->owner();
        $this->seedNames($owner, 'Person', 25);

        foreach (['-3', '0', 'abc'] as $invalid) {
            $response = $this->actingAs($owner)
                ->getJson(route('api.contacts.index', ['page' => $invalid]))
                ->assertOk();

            $response->assertJsonPath('current_page', 1);
            $this->assertCount(PageSize::DEFAULT, $response->json('data'));
        }
    }

    public function test_search_filter_is_applied_before_pagination(): void
    {
        $owner = $this->owner();
        $this->seedNames($owner, 'Match', 30);
        $this->seedNames($owner, 'Other', 5);

        // 30 matches at 10 per page => 3 pages, all rows on page 3 are matches.
        $response = $this->actingAs($owner)
            ->getJson(route('api.contacts.index', ['search' => 'Match', 'per_page' => 10]))
            ->assertOk();

        $this->assertSame(30, $response->json('total'));
        $response->assertJsonPath('last_page', 3);

        $page3 = $this->actingAs($owner)
            ->getJson(route('api.contacts.index', ['search' => 'Match', 'per_page' => 10, 'page' => 3]))
            ->assertOk();

        $this->assertCount(10, $page3->json('data'));
        foreach ($page3->json('data') as $row) {
            $this->assertStringStartsWith('Match', $row['name']);
        }
    }

    public function test_sorting_is_deterministic_with_a_unique_tie_breaker(): void
    {
        $owner = $this->owner();
        // Same name for every row: ordering must still be stable, by id ascending.
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->contact($owner, ['name' => 'Same Name'])->id;
        }
        $expected = $ids;
        sort($expected);

        $page1 = $this->actingAs($owner)
            ->getJson(route('api.contacts.index', ['per_page' => 2, 'page' => 1]))
            ->assertOk();
        $page2 = $this->actingAs($owner)
            ->getJson(route('api.contacts.index', ['per_page' => 2, 'page' => 2]))
            ->assertOk();
        $page3 = $this->actingAs($owner)
            ->getJson(route('api.contacts.index', ['per_page' => 2, 'page' => 3]))
            ->assertOk();

        $idsAcrossPages = collect([...$page1->json('data'), ...$page2->json('data'), ...$page3->json('data')])
            ->pluck('id')
            ->all();

        $this->assertSame($expected, $idsAcrossPages);
    }

    public function test_pagination_is_scoped_to_the_account(): void
    {
        $owner = $this->owner();
        $intruder = $this->owner();
        $this->seedNames($owner, 'Owner', 5);
        $this->seedNames($intruder, 'Intruder', 5);

        $response = $this->actingAs($owner)
            ->getJson(route('api.contacts.index', ['per_page' => 100]))
            ->assertOk();

        $this->assertSame(5, $response->json('total'));
        foreach ($response->json('data') as $row) {
            $this->assertStringStartsWith('Owner', $row['name']);
        }
    }

    public function test_linked_guests_endpoint_uses_the_bounded_page_size(): void
    {
        $owner = $this->owner();
        $contact = $this->contact($owner, ['name' => 'Linked']);
        $event = Event::factory()->create(['user_id' => $owner->id]);

        for ($i = 1; $i <= 3; $i++) {
            Guest::factory()->create([
                'event_id' => $event->id,
                'contact_id' => $contact->id,
                'name' => "Guest {$i}",
            ]);
        }

        $response = $this->actingAs($owner)
            ->getJson(route('api.contacts.guests', ['contact' => $contact->id, 'per_page' => 2]))
            ->assertOk();

        $this->assertSame(2, $response->json('per_page'));
        $this->assertCount(2, $response->json('data'));
        $this->assertSame(3, $response->json('total'));

        $clamped = $this->actingAs($owner)
            ->getJson(route('api.contacts.guests', ['contact' => $contact->id, 'per_page' => 9999]))
            ->assertOk();

        $this->assertSame(PageSize::MAX, $clamped->json('per_page'));
    }

    public function test_contact_read_routes_use_the_existing_admin_read_limiter(): void
    {
        $router = app('router');

        foreach (['api.contacts.index', 'api.contacts.show', 'api.contacts.guests'] as $name) {
            $middleware = $router->getRoutes()->getByName($name)->gatherMiddleware();
            $this->assertContains('throttle:admin-read', $middleware, "{$name} should use the admin-read limiter");
        }

        // Mutations keep the write limiter (unchanged by this increment).
        foreach (['api.contacts.update', 'api.contacts.destroy'] as $name) {
            $middleware = $router->getRoutes()->getByName($name)->gatherMiddleware();
            $this->assertContains('throttle:admin-write', $middleware, "{$name} should use the admin-write limiter");
        }
    }

    public function test_legitimate_paginated_requests_are_not_throttled_within_the_limit(): void
    {
        $owner = $this->owner();
        $this->seedNames($owner, 'Person', 45);

        // A handful of normal page/per_page requests stay well under the 120/min
        // admin-read cap.
        foreach ([1, 2, 3] as $page) {
            $this->actingAs($owner)
                ->getJson(route('api.contacts.index', ['page' => $page, 'per_page' => 20]))
                ->assertOk();
        }
    }
}
