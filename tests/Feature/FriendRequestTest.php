<?php

namespace Tests\Feature;

use App\Models\FriendRequest;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

class FriendRequestTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_user_can_send_accept_and_list_friend_requests(): void
    {
        $alice = $this->userWithProfile('alice');
        $bob = $this->userWithProfile('bob');

        $this->actingAs($alice)->postJson('/api/friends', ['user_id' => $bob->id])
            ->assertCreated()->assertJsonPath('status', 'pending');

        // Only one pending row exists per pair; a duplicate from the same sender is refused.
        $this->actingAs($alice)->postJson('/api/friends', ['user_id' => $bob->id])->assertStatus(422);
        $this->assertDatabaseCount('friend_requests', 1);

        $incoming = $this->actingAs($bob)->getJson('/api/friends')->assertOk()->json();
        $this->assertSame(1, $incoming['counts']['incoming']);
        $this->assertSame($alice->id, $incoming['incoming']['data'][0]['user']['id']);

        $requestId = $incoming['incoming']['data'][0]['id'];
        $this->actingAs($alice)->postJson("/api/friends/{$requestId}/accept")->assertForbidden();

        $this->actingAs($bob)->postJson("/api/friends/{$requestId}/accept")
            ->assertOk()->assertJsonPath('status', 'accepted');

        $this->assertTrue($alice->fresh()->isFriendsWith($bob->id));
        $this->assertTrue($bob->fresh()->isFriendsWith($alice->id));
        $this->assertDatabaseCount('friendships', 2);
        $this->assertDatabaseHas('friend_requests', ['id' => $requestId, 'status' => 'accepted']);
        $this->assertDatabaseHas('notifications', ['user_id' => $alice->id, 'type' => 'friend_request_accepted']);

        $friends = $this->actingAs($bob)->getJson('/api/friends')->json();
        $this->assertSame(1, $friends['counts']['friends']);
        $this->assertSame($alice->id, $friends['friends']['data'][0]['id']);
    }

    public function test_requests_can_be_rejected_cancelled_and_friends_removed(): void
    {
        $alice = $this->userWithProfile('alice');
        $bob = $this->userWithProfile('bob');

        $this->actingAs($alice)->postJson('/api/friends', ['user_id' => $bob->id])->assertCreated();
        $id = FriendRequest::first()->id;

        // Only the sender may cancel.
        $this->actingAs($bob)->deleteJson("/api/friends/{$id}")->assertForbidden();
        $this->actingAs($alice)->deleteJson("/api/friends/{$id}")
            ->assertOk()->assertJsonPath('status', 'cancelled');

        // A cancelled request may be sent again.
        $this->actingAs($alice)->postJson('/api/friends', ['user_id' => $bob->id])->assertCreated();
        $request = FriendRequest::first();
        $this->actingAs($bob)->postJson("/api/friends/{$request->id}/reject")
            ->assertOk()->assertJsonPath('status', 'rejected');

        // Re-friend directly for the removal check.
        $this->befriend($alice, $bob);
        $this->actingAs($alice)->deleteJson("/api/friends/user/{$bob->id}")->assertOk();
        $this->assertFalse($alice->fresh()->isFriendsWith($bob->id));
        $this->assertDatabaseCount('friendships', 0);
    }

    public function test_cannot_friend_self_or_unknown_user(): void
    {
        $alice = $this->userWithProfile('alice');

        $this->actingAs($alice)->postJson('/api/friends', ['user_id' => $alice->id])->assertStatus(422);
        $this->actingAs($alice)->postJson('/api/friends', ['user_id' => 99999])->assertStatus(422);
        $this->assertDatabaseCount('friend_requests', 0);
        $this->assertSame(0, Notification::count());
    }
}
