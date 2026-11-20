<?php

use App\Models\FriendRequest;
use App\Models\Notification;

it('lets a user send, accept, and list friend requests', function () {
    $alice = $this->userWithProfile('alice');
    $bob = $this->userWithProfile('bob');

    $this->actingAs($alice)->postJson('/api/friends', ['user_id' => $bob->id])
        ->assertCreated()->assertJsonPath('status', 'pending');

    $this->actingAs($alice)->postJson('/api/friends', ['user_id' => $bob->id])->assertStatus(422);
    $this->assertDatabaseCount('friend_requests', 1);

    $incoming = $this->actingAs($bob)->getJson('/api/friends')->assertOk()->json();
    $this->assertSame(1, $incoming['counts']['incoming']);
    $this->assertSame($alice->id, $incoming['incoming'][0]['user']['id']);

    $requestId = $incoming['incoming'][0]['id'];
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
    $this->assertSame($alice->id, $friends['friends'][0]['id']);
});

it('allows requests to be rejected, cancelled, and friends removed', function () {
    $alice = $this->userWithProfile('alice');
    $bob = $this->userWithProfile('bob');

    $this->actingAs($alice)->postJson('/api/friends', ['user_id' => $bob->id])->assertCreated();
    $id = FriendRequest::first()->id;

    $this->actingAs($bob)->deleteJson("/api/friends/{$id}")->assertForbidden();
    $this->actingAs($alice)->deleteJson("/api/friends/{$id}")
        ->assertOk()->assertJsonPath('status', 'cancelled');

    $this->actingAs($alice)->postJson('/api/friends', ['user_id' => $bob->id])->assertCreated();
    $request = FriendRequest::first();
    $this->actingAs($bob)->postJson("/api/friends/{$request->id}/reject")
        ->assertOk()->assertJsonPath('status', 'rejected');

    $this->befriend($alice, $bob);
    $this->actingAs($alice)->deleteJson("/api/friends/user/{$bob->id}")->assertOk();
    $this->assertFalse($alice->fresh()->isFriendsWith($bob->id));
    $this->assertDatabaseCount('friendships', 0);
});

it('keeps request notes optional and visible to both sides', function () {
    $alice = $this->userWithProfile('alice');
    $bob = $this->userWithProfile('bob');

    $this->actingAs($alice)->postJson('/api/friends', [
        'user_id' => $bob->id,
        'note' => 'Hey Bob, it is Alice!',
    ])->assertCreated()->assertJsonPath('note', 'Hey Bob, it is Alice!');

    $this->assertDatabaseHas('friend_requests', [
        'sender_id' => $alice->id,
        'recipient_id' => $bob->id,
        'note' => 'Hey Bob, it is Alice!',
    ]);

    $incoming = $this->actingAs($bob)->getJson('/api/friends')->json();
    $this->assertSame('Hey Bob, it is Alice!', $incoming['incoming'][0]['note']);

    $outgoing = $this->actingAs($alice)->getJson('/api/friends')->json();
    $this->assertSame('Hey Bob, it is Alice!', $outgoing['outgoing'][0]['note']);

    $requestId = FriendRequest::first()->id;
    $this->actingAs($alice)->deleteJson("/api/friends/{$requestId}")->assertOk();
    $this->actingAs($alice)->postJson('/api/friends', [
        'user_id' => $bob->id,
        'note' => '   ',
    ])->assertCreated()->assertJsonPath('note', null);
    $this->assertDatabaseHas('friend_requests', [
        'sender_id' => $alice->id,
        'note' => null,
    ]);

    $requestId = FriendRequest::first()->id;
    $this->actingAs($alice)->deleteJson("/api/friends/{$requestId}")->assertOk();
    $this->actingAs($alice)->postJson('/api/friends', [
        'user_id' => $bob->id,
        'note' => str_repeat('x', 161),
    ])->assertStatus(422)->assertJsonValidationErrors('note');
    $this->assertSame(0, FriendRequest::where('status', 'pending')->count());
});

it('prevents friending self or an unknown user', function () {
    $alice = $this->userWithProfile('alice');

    $this->actingAs($alice)->postJson('/api/friends', ['user_id' => $alice->id])->assertStatus(422);
    $this->actingAs($alice)->postJson('/api/friends', ['user_id' => 99999])->assertStatus(422);
    $this->assertDatabaseCount('friend_requests', 0);
    $this->assertSame(0, Notification::count());
});
