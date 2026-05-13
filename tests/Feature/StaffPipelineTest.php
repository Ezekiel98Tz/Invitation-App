<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\StaffInvite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class StaffPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_invite_staff_and_staff_can_accept_and_access_owner_events(): void
    {
        Notification::fake();

        $owner = User::factory()->create();

        $inviteEmail = 'staff@example.test';

        $this->actingAs($owner)
            ->postJson(route('admin.staff.invite'), ['email' => $inviteEmail])
            ->assertStatus(201);

        $invite = StaffInvite::query()->where('owner_id', $owner->id)->where('email', $inviteEmail)->first();
        $this->assertNotNull($invite);

        $staff = User::factory()->create(['email' => $inviteEmail]);

        $this->actingAs($staff)
            ->postJson(route('staff-invites.accept', ['token' => $invite->token]))
            ->assertOk()
            ->assertJson(['accepted' => true]);

        $staff->refresh();
        $this->assertSame('staff', $staff->role);
        $this->assertSame($owner->id, $staff->owner_id);

        $event1 = Event::factory()->create(['user_id' => $owner->id]);
        $event2 = Event::factory()->create(['user_id' => User::factory()->create()->id]);

        $this->actingAs($staff)
            ->getJson(route('events.index'))
            ->assertOk()
            ->assertJsonFragment(['id' => $event1->id])
            ->assertJsonMissing(['id' => $event2->id]);

        $this->actingAs($staff)
            ->getJson(route('admin.events.invitations.preview', ['event' => $event1->id]))
            ->assertOk();

        $this->actingAs($staff)
            ->deleteJson(route('events.destroy', ['event' => $event1->id]))
            ->assertForbidden();
    }
}
