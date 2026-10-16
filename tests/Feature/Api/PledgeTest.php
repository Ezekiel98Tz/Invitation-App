<?php

namespace Tests\Feature\Api;

use App\Models\Celebration;
use App\Models\Contact;
use App\Models\Event;
use App\Models\Guest;
use App\Models\InvitationCredential;
use App\Models\Pledge;
use App\Models\PledgeFulfillment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PledgeTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create();
    }

    private function staff(User $owner): User
    {
        return User::factory()->create(['role' => 'staff', 'owner_id' => $owner->id]);
    }

    private function event(User $owner, array $attributes = []): Event
    {
        return Event::factory()->create(array_merge([
            'user_id' => $owner->id,
            'event_start' => now()->addDays(7),
            // This suite exercises pledge/contribution endpoints, which now
            // require the `contributions` capability. The cross-workflow test
            // also checks a guest in, so `check_in` is enabled here too.
            'settings' => [
                'capabilities' => [
                    'contributions' => true,
                    'check_in' => true,
                ],
            ],
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function guest(Event $event, array $attributes = []): Guest
    {
        return Guest::factory()->create(array_merge([
            'event_id' => $event->id,
            'status' => 'pending',
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function contact(User $owner, array $attributes = []): Contact
    {
        return Contact::factory()->create(array_merge([
            'user_id' => $owner->id,
            'phone' => null,
            'normalized_phone' => null,
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

    // ------------------------------------------------------------- authentication

    public function test_pledge_endpoints_require_authentication(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $pledge = $this->pledge($event);
        $fulfillment = PledgeFulfillment::factory()->create(['pledge_id' => $pledge->id]);

        $this->getJson(route('api.events.pledges.index', $event))->assertStatus(401);
        $this->getJson(route('api.events.pledges.summary', $event))->assertStatus(401);
        $this->getJson(route('api.events.pledges.show', [$event, $pledge]))->assertStatus(401);
        $this->postJson(route('api.events.pledges.store', $event), [])->assertStatus(401);
        $this->patchJson(route('api.events.pledges.update', [$event, $pledge]), [])->assertStatus(401);
        $this->postJson(route('api.events.pledges.cancel', [$event, $pledge]))->assertStatus(401);
        $this->postJson(route('api.events.pledges.fulfillments.store', [$event, $pledge]), [])->assertStatus(401);
        $this->postJson(route('api.events.pledges.fulfillments.reverse', [$event, $pledge, $fulfillment]))->assertStatus(401);
        $this->getJson(route('api.reports.pledges'))->assertStatus(401);
    }

    // --------------------------------------------------------------- create / edit

    public function test_manual_create_records_snapshot_balance_and_audit(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);

        $response = $this->actingAs($owner)->postJson(route('api.events.pledges.store', $event), [
            'pledger_name' => 'Acme Ltd',
            'pledger_email' => 'acme@example.test',
            'pledger_phone' => '+255700000010',
            'currency' => 'tzs',
            'pledged_amount_minor' => 500000,
            'note' => 'pledged at the site visit',
        ])->assertStatus(201);

        $response
            ->assertJsonPath('currency', 'TZS')
            ->assertJsonPath('pledged_amount_minor', 500000)
            ->assertJsonPath('fulfilled_minor', 0)
            ->assertJsonPath('outstanding_minor', 500000)
            ->assertJsonPath('derived_status', 'open')
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('source', 'manual');

        $id = $response->json('id');

        $this->assertDatabaseHas('pledges', [
            'id' => $id,
            'user_id' => $owner->id,
            'event_id' => $event->id,
            'currency' => 'TZS',
            'pledged_amount_minor' => 500000,
            'status' => 'active',
            'source' => 'manual',
            'created_by' => $owner->id,
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $owner->id,
            'action' => 'pledge.create',
            'auditable_type' => Pledge::class,
            'auditable_id' => $id,
        ]);
    }

    public function test_create_validates_currency_amount_and_name(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);

        $this->actingAs($owner)->postJson(route('api.events.pledges.store', $event), [
            'currency' => 'US',
            'pledged_amount_minor' => 100,
            'pledger_name' => 'X',
        ])->assertStatus(422)->assertJsonValidationErrors('currency');

        $this->actingAs($owner)->postJson(route('api.events.pledges.store', $event), [
            'currency' => 'USD',
            'pledged_amount_minor' => -5,
            'pledger_name' => 'X',
        ])->assertStatus(422)->assertJsonValidationErrors('pledged_amount_minor');

        // No name and no linked identity to snapshot from.
        $this->actingAs($owner)->postJson(route('api.events.pledges.store', $event), [
            'currency' => 'USD',
            'pledged_amount_minor' => 100,
        ])->assertStatus(422)->assertJsonValidationErrors('pledger_name');
    }

    public function test_create_snapshots_identity_from_a_linked_guest(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $guest = $this->guest($event, [
            'name' => 'Guest Snapshot',
            'email' => 'guest@example.test',
            'phone' => '+255700000011',
        ]);

        $this->actingAs($owner)->postJson(route('api.events.pledges.store', $event), [
            'guest_id' => $guest->id,
            'currency' => 'USD',
            'pledged_amount_minor' => 1000,
        ])->assertStatus(201)
            ->assertJsonPath('guest_id', $guest->id)
            ->assertJsonPath('pledger_name', 'Guest Snapshot')
            ->assertJsonPath('pledger_email', 'guest@example.test')
            ->assertJsonPath('pledger_phone', '+255700000011');
    }

    public function test_create_rejects_a_guest_from_another_event(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $otherEvent = $this->event($owner);
        $foreignGuest = $this->guest($otherEvent);

        $this->actingAs($owner)->postJson(route('api.events.pledges.store', $event), [
            'guest_id' => $foreignGuest->id,
            'currency' => 'USD',
            'pledged_amount_minor' => 1000,
            'pledger_name' => 'X',
        ])->assertStatus(422)->assertJsonValidationErrors('guest_id');
    }

    public function test_update_edits_explicit_fields_and_writes_an_audit_log(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $pledge = $this->pledge($event, ['pledger_name' => 'Old Name', 'pledged_amount_minor' => 1000]);

        $this->actingAs($owner)
            ->patchJson(route('api.events.pledges.update', [$event, $pledge]), [
                'pledger_name' => 'New Name',
                'pledged_amount_minor' => 2500,
                'note' => 'revised estimate',
            ])
            ->assertOk()
            ->assertJsonPath('pledger_name', 'New Name')
            ->assertJsonPath('pledged_amount_minor', 2500)
            ->assertJsonPath('outstanding_minor', 2500)
            ->assertJsonPath('derived_status', 'open');

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $owner->id,
            'action' => 'pledge.update',
            'auditable_type' => Pledge::class,
            'auditable_id' => $pledge->id,
        ]);
    }

    public function test_update_ignores_client_supplied_ownership_and_status(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $other = $this->owner();
        $pledge = $this->pledge($event, ['pledger_name' => 'Keep']);

        $this->actingAs($owner)
            ->patchJson(route('api.events.pledges.update', [$event, $pledge]), [
                'pledger_name' => 'Renamed',
                'user_id' => $other->id,
                'event_id' => $this->event($other)->id,
                'status' => Pledge::STATUS_CANCELLED,
            ])
            ->assertOk();

        $pledge->refresh();
        $this->assertSame('Renamed', $pledge->pledger_name);
        $this->assertSame($owner->id, $pledge->user_id);
        $this->assertSame($event->id, $pledge->event_id);
        $this->assertSame(Pledge::STATUS_ACTIVE, $pledge->status);
    }

    // ------------------------------------------------------------------- currency

    public function test_currency_can_change_before_any_fulfillment(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $pledge = $this->pledge($event, ['currency' => 'USD']);

        $this->actingAs($owner)
            ->patchJson(route('api.events.pledges.update', [$event, $pledge]), ['currency' => 'kes'])
            ->assertOk()
            ->assertJsonPath('currency', 'KES');

        $this->assertSame('KES', $pledge->fresh()->currency);
    }

    public function test_currency_is_immutable_once_a_fulfillment_exists(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $pledge = $this->pledge($event, ['currency' => 'USD']);
        PledgeFulfillment::factory()->create(['pledge_id' => $pledge->id, 'amount_minor' => 500]);

        $this->actingAs($owner)
            ->patchJson(route('api.events.pledges.update', [$event, $pledge]), ['currency' => 'KES'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('currency');

        $this->assertSame('USD', $pledge->fresh()->currency);
    }

    // ------------------------------------------------------------------ isolation

    public function test_index_is_scoped_and_filters_by_status_method_and_search(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);

        $cash = $this->pledge($event, ['pledger_name' => 'Cash Pledge']);
        PledgeFulfillment::factory()->create([
            'pledge_id' => $cash->id,
            'method' => PledgeFulfillment::METHOD_CASH,
            'amount_minor' => 1000,
        ]);
        $this->pledge($event, ['pledger_name' => 'Cancelled Pledge'])->forceFill(['status' => Pledge::STATUS_CANCELLED])->save();
        $this->pledge($event, ['pledger_name' => 'Zawadi Group']);

        // A pledge in another account must never leak.
        $this->pledge($this->event($this->owner()));

        $this->actingAs($owner)
            ->getJson(route('api.events.pledges.index', $event))
            ->assertOk()
            ->assertJsonPath('total', 3);

        $this->actingAs($owner)
            ->getJson(route('api.events.pledges.index', ['event' => $event->id, 'status' => 'cancelled']))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.pledger_name', 'Cancelled Pledge');

        $this->actingAs($owner)
            ->getJson(route('api.events.pledges.index', ['event' => $event->id, 'method' => 'cash']))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.pledger_name', 'Cash Pledge');

        $this->actingAs($owner)
            ->getJson(route('api.events.pledges.index', ['event' => $event->id, 'search' => 'Zawadi']))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.pledger_name', 'Zawadi Group');
    }

    public function test_cross_account_access_is_forbidden(): void
    {
        $owner = $this->owner();
        $intruder = $this->owner();
        $event = $this->event($owner);
        $pledge = $this->pledge($event);

        $this->actingAs($intruder)->getJson(route('api.events.pledges.index', $event))->assertForbidden();
        $this->actingAs($intruder)->getJson(route('api.events.pledges.summary', $event))->assertForbidden();
        $this->actingAs($intruder)->getJson(route('api.events.pledges.show', [$event, $pledge]))->assertForbidden();
        $this->actingAs($intruder)->patchJson(route('api.events.pledges.update', [$event, $pledge]), ['pledger_name' => 'Hacked'])->assertForbidden();
        $this->actingAs($intruder)->postJson(route('api.events.pledges.cancel', [$event, $pledge]))->assertForbidden();

        $this->assertSame($pledge->pledger_name, $pledge->fresh()->pledger_name);
        $this->assertSame(Pledge::STATUS_ACTIVE, $pledge->fresh()->status);
    }

    public function test_staff_can_manage_their_owners_pledges(): void
    {
        $owner = $this->owner();
        $staff = $this->staff($owner);
        $event = $this->event($owner);

        $this->actingAs($staff)
            ->postJson(route('api.events.pledges.store', $event), [
                'pledger_name' => 'Staff Pledge',
                'currency' => 'USD',
                'pledged_amount_minor' => 4000,
            ])
            ->assertStatus(201);

        $pledge = Pledge::where('event_id', $event->id)->firstOrFail();

        $this->actingAs($staff)
            ->getJson(route('api.events.pledges.index', $event))
            ->assertOk()
            ->assertJsonPath('total', 1);

        $this->actingAs($staff)
            ->postJson(route('api.events.pledges.fulfillments.store', [$event, $pledge]), [
                'amount_minor' => 1000,
                'method' => 'cash',
            ])
            ->assertStatus(201);

        $this->actingAs($staff)
            ->postJson(route('api.events.pledges.cancel', [$event, $pledge]))
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');
    }

    public function test_staff_of_another_owner_cannot_access(): void
    {
        $owner = $this->owner();
        $otherOwner = $this->owner();
        $staff = $this->staff($otherOwner);
        $event = $this->event($owner);
        $this->pledge($event);

        $this->actingAs($staff)->getJson(route('api.events.pledges.index', $event))->assertForbidden();
    }

    // ------------------------------------------------------------------ snapshots

    public function test_deleting_the_linked_guest_preserves_the_pledge_snapshot(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $guest = $this->guest($event, ['name' => 'Guest Kept']);
        $pledge = $this->pledge($event, [
            'guest_id' => $guest->id,
            'pledger_name' => 'Snapshot Name',
            'pledger_email' => 'snapshot@example.test',
        ]);

        $guest->delete();

        $pledge->refresh();
        $this->assertNull($pledge->guest_id);
        $this->assertSame('Snapshot Name', $pledge->pledger_name);
        $this->assertSame('snapshot@example.test', $pledge->pledger_email);
        $this->assertDatabaseHas('pledges', ['id' => $pledge->id]);
    }

    public function test_deleting_the_linked_contact_preserves_the_pledge_snapshot(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $contact = $this->contact($owner, ['name' => 'Contact Kept']);
        $pledge = $this->pledge($event, [
            'contact_id' => $contact->id,
            'pledger_name' => 'Snapshot Name',
        ]);

        $contact->delete();

        $pledge->refresh();
        $this->assertNull($pledge->contact_id);
        $this->assertSame('Snapshot Name', $pledge->pledger_name);
        $this->assertDatabaseHas('pledges', ['id' => $pledge->id]);
    }

    // --------------------------------------------------------------- fulfillments

    public function test_partial_multiple_and_over_fulfillment_balances(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $pledge = $this->pledge($event, ['pledged_amount_minor' => 10000]);

        $this->actingAs($owner)
            ->postJson(route('api.events.pledges.fulfillments.store', [$event, $pledge]), ['amount_minor' => 4000, 'method' => 'cash'])
            ->assertStatus(201)
            ->assertJsonPath('pledge.fulfilled_minor', 4000)
            ->assertJsonPath('pledge.outstanding_minor', 6000)
            ->assertJsonPath('pledge.derived_status', 'partially_fulfilled');

        $this->actingAs($owner)
            ->postJson(route('api.events.pledges.fulfillments.store', [$event, $pledge]), ['amount_minor' => 6000, 'method' => 'bank_transfer'])
            ->assertStatus(201)
            ->assertJsonPath('pledge.fulfilled_minor', 10000)
            ->assertJsonPath('pledge.derived_status', 'fulfilled');

        // Over-fulfillment is allowed and surfaced, never clamped.
        $this->actingAs($owner)
            ->postJson(route('api.events.pledges.fulfillments.store', [$event, $pledge]), ['amount_minor' => 1500, 'method' => 'mobile_money'])
            ->assertStatus(201)
            ->assertJsonPath('pledge.fulfilled_minor', 11500)
            ->assertJsonPath('pledge.outstanding_minor', 0)
            ->assertJsonPath('pledge.derived_status', 'over_fulfilled');

        $this->assertSame(3, PledgeFulfillment::where('pledge_id', $pledge->id)->count());
    }

    public function test_fulfillment_validation_rejects_nonpositive_amount_and_bad_method(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $pledge = $this->pledge($event);

        $this->actingAs($owner)
            ->postJson(route('api.events.pledges.fulfillments.store', [$event, $pledge]), ['amount_minor' => 0])
            ->assertStatus(422)->assertJsonValidationErrors('amount_minor');

        $this->actingAs($owner)
            ->postJson(route('api.events.pledges.fulfillments.store', [$event, $pledge]), ['amount_minor' => -100])
            ->assertStatus(422)->assertJsonValidationErrors('amount_minor');

        $this->actingAs($owner)
            ->postJson(route('api.events.pledges.fulfillments.store', [$event, $pledge]), ['amount_minor' => 100, 'method' => 'bitcoin'])
            ->assertStatus(422)->assertJsonValidationErrors('method');
    }

    public function test_reversal_compensates_and_preserves_history(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $pledge = $this->pledge($event, ['pledged_amount_minor' => 10000]);

        $original = PledgeFulfillment::factory()->create([
            'pledge_id' => $pledge->id,
            'amount_minor' => 4000,
            'method' => 'cash',
        ]);

        $response = $this->actingAs($owner)
            ->postJson(route('api.events.pledges.fulfillments.reverse', [$event, $pledge, $original]), [
                'note' => 'reported in error',
            ])
            ->assertOk();

        $response
            ->assertJsonPath('fulfillment.is_reversal', true)
            ->assertJsonPath('fulfillment.reverses_fulfillment_id', $original->id)
            ->assertJsonPath('fulfillment.status', 'reversed')
            ->assertJsonPath('pledge.fulfilled_minor', 0)
            ->assertJsonPath('pledge.outstanding_minor', 10000)
            ->assertJsonPath('pledge.derived_status', 'open');

        // The original row is untouched (history preserved, not edited/deleted).
        $original->refresh();
        $this->assertSame(PledgeFulfillment::STATUS_RECORDED, $original->status);
        $this->assertSame(4000, $original->amount_minor);
        $this->assertNull($original->reverses_fulfillment_id);

        // A compensating entry referencing it exists.
        $this->assertDatabaseHas('pledge_fulfillments', [
            'pledge_id' => $pledge->id,
            'reverses_fulfillment_id' => $original->id,
            'status' => 'reversed',
            'amount_minor' => 4000,
        ]);
        $this->assertSame(2, PledgeFulfillment::where('pledge_id', $pledge->id)->count());
    }

    public function test_a_fulfillment_cannot_be_reversed_twice(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $pledge = $this->pledge($event);
        $original = PledgeFulfillment::factory()->create([
            'pledge_id' => $pledge->id,
            'amount_minor' => 2000,
        ]);

        $this->actingAs($owner)
            ->postJson(route('api.events.pledges.fulfillments.reverse', [$event, $pledge, $original]))
            ->assertOk();

        $this->actingAs($owner)
            ->postJson(route('api.events.pledges.fulfillments.reverse', [$event, $pledge, $original]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('fulfillment');
    }

    public function test_cannot_record_a_fulfillment_against_a_cancelled_pledge(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $pledge = $this->pledge($event);

        $this->actingAs($owner)->postJson(route('api.events.pledges.cancel', [$event, $pledge]))->assertOk();

        $this->actingAs($owner)
            ->postJson(route('api.events.pledges.fulfillments.store', [$event, $pledge]), ['amount_minor' => 100])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pledge');
    }

    // --------------------------------------------------------------------- cancel

    public function test_cancel_sets_status_and_guards_against_double_cancel(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $pledge = $this->pledge($event);

        $this->actingAs($owner)
            ->postJson(route('api.events.pledges.cancel', [$event, $pledge]))
            ->assertOk()
            ->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('derived_status', 'cancelled');

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $owner->id,
            'action' => 'pledge.cancel',
            'auditable_id' => $pledge->id,
        ]);

        $this->actingAs($owner)
            ->postJson(route('api.events.pledges.cancel', [$event, $pledge]))
            ->assertStatus(422);
    }

    // -------------------------------------------------------------------- summary

    public function test_event_summary_aggregates_amounts_counts_and_methods(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);

        $partial = $this->pledge($event, ['pledged_amount_minor' => 10000]);
        PledgeFulfillment::factory()->create(['pledge_id' => $partial->id, 'amount_minor' => 4000, 'method' => 'cash']);

        $fulfilled = $this->pledge($event, ['pledged_amount_minor' => 5000]);
        PledgeFulfillment::factory()->create(['pledge_id' => $fulfilled->id, 'amount_minor' => 5000, 'method' => 'bank_transfer']);

        // Cancelled pledges are excluded from money totals but still counted.
        $cancelled = $this->pledge($event, ['pledged_amount_minor' => 2000]);
        $cancelled->forceFill(['status' => Pledge::STATUS_CANCELLED])->save();

        $response = $this->actingAs($owner)
            ->getJson(route('api.events.pledges.summary', $event))
            ->assertOk();

        $response
            ->assertJsonPath('total_pledges', 3)
            ->assertJsonPath('counts.partially_fulfilled', 1)
            ->assertJsonPath('counts.fulfilled', 1)
            ->assertJsonPath('counts.cancelled', 1)
            ->assertJsonPath('status_counts.active', 2)
            ->assertJsonPath('status_counts.cancelled', 1);

        $currencies = collect($response->json('currencies'));
        $usd = $currencies->firstWhere('currency', 'USD');
        $this->assertSame(2, $usd['pledges']);
        $this->assertSame(15000, $usd['pledged_minor']);
        $this->assertSame(9000, $usd['fulfilled_minor']);
        $this->assertSame(6000, $usd['outstanding_minor']);

        $methods = collect($response->json('by_method'))->keyBy('method');
        $this->assertSame(4000, $methods['cash']['amount_minor']);
        $this->assertSame(1, $methods['cash']['entries']);
        $this->assertSame(5000, $methods['bank_transfer']['amount_minor']);
    }

    public function test_summary_keeps_different_currencies_separate(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);

        $this->pledge($event, ['currency' => 'USD', 'pledged_amount_minor' => 10000]);
        $tzs = $this->pledge($event, ['currency' => 'TZS', 'pledged_amount_minor' => 20000]);
        PledgeFulfillment::factory()->create(['pledge_id' => $tzs->id, 'amount_minor' => 5000, 'method' => 'cash']);

        $currencies = collect(
            $this->actingAs($owner)->getJson(route('api.events.pledges.summary', $event))->assertOk()->json('currencies')
        )->keyBy('currency');

        $this->assertCount(2, $currencies);
        $this->assertSame(10000, $currencies['USD']['pledged_minor']);
        $this->assertSame(0, $currencies['USD']['fulfilled_minor']);
        $this->assertSame(20000, $currencies['TZS']['pledged_minor']);
        $this->assertSame(5000, $currencies['TZS']['fulfilled_minor']);
        $this->assertSame(15000, $currencies['TZS']['outstanding_minor']);
    }

    public function test_account_report_is_scoped_to_the_owner(): void
    {
        $owner = $this->owner();
        $other = $this->owner();

        $eventA = $this->event($owner);
        $eventB = $this->event($owner);
        $this->pledge($eventA, ['pledged_amount_minor' => 10000]);
        $this->pledge($eventB, ['pledged_amount_minor' => 5000]);
        $this->pledge($this->event($other), ['pledged_amount_minor' => 999999]);

        $response = $this->actingAs($owner)->getJson(route('api.reports.pledges'))->assertOk();

        $response->assertJsonPath('total_pledges', 2);
        $response->assertJsonPath('status_counts.active', 2);

        $eventIds = collect($response->json('events'))->pluck('event_id')->all();
        $this->assertContains($eventA->id, $eventIds);
        $this->assertContains($eventB->id, $eventIds);
        $this->assertCount(2, $eventIds);
    }

    public function test_celebration_report_aggregates_only_member_events(): void
    {
        $owner = $this->owner();
        $celebration = Celebration::factory()->create(['user_id' => $owner->id]);

        $memberA = $this->event($owner, ['celebration_id' => $celebration->id]);
        $memberB = $this->event($owner, ['celebration_id' => $celebration->id]);
        $outside = $this->event($owner);

        $this->pledge($memberA, ['pledged_amount_minor' => 10000]);
        $this->pledge($memberB, ['pledged_amount_minor' => 20000]);
        $this->pledge($outside, ['pledged_amount_minor' => 777777]);

        $response = $this->actingAs($owner)
            ->getJson(route('api.reports.celebrations.pledges', $celebration))
            ->assertOk();

        $response
            ->assertJsonPath('celebration_id', $celebration->id)
            ->assertJsonPath('event_count', 2)
            ->assertJsonPath('total_pledges', 2);

        $eventIds = collect($response->json('events'))->pluck('event_id')->all();
        $this->assertContains($memberA->id, $eventIds);
        $this->assertContains($memberB->id, $eventIds);
        $this->assertNotContains($outside->id, $eventIds);

        $usd = collect($response->json('currencies'))->firstWhere('currency', 'USD');
        $this->assertSame(30000, $usd['pledged_minor']);
    }

    // ------------------------------------------------------- never blocks workflows

    public function test_an_outstanding_pledge_never_blocks_rsvp_credential_or_check_in(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner, ['event_start' => now()->addHour()]);
        $guest = $this->guest($event, ['status' => 'pending']);

        // A large outstanding pledge for this event.
        $pledge = $this->pledge($event, ['pledged_amount_minor' => 5000000]);
        $this->assertSame('open', $pledge->derivedStatus());
        $this->assertSame(5000000, $pledge->outstandingMinor());

        // RSVP accept still works and issues an entry credential.
        $this->postJson(route('api.invites.store', ['token' => $guest->invite_token]), [
            'status' => 'accepted',
        ])->assertOk();

        $credential = InvitationCredential::where('guest_id', $guest->id)->first();
        $this->assertNotNull($credential);
        $this->assertSame(InvitationCredential::STATUS_ACTIVE, $credential->status);

        // The public invitation endpoint still resolves.
        $this->getJson(route('api.invites.show', ['token' => $guest->invite_token]))->assertOk();

        // Check-in still works.
        $this->actingAs($owner)
            ->postJson(route('api.events.guests.check-in', ['event' => $event->id, 'guest' => $guest->id]), [
                'method' => 'manual',
            ])
            ->assertStatus(201);

        // And the pledge is still outstanding — no side effects in either direction.
        $this->assertSame(5000000, $pledge->fresh()->outstandingMinor());
    }
}
