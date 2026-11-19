<?php

use App\Models\FriendRequest;
use App\Models\MessageRequest;
use App\Models\UserBlock;

describe('sending friend requests', function () {
    it('sends a request with and without an optional note', function (?string $note, ?string $stored) {
        $alice = makeUser('alice');
        $bob = makeUser('bob');

        actingAsUser($alice)->postJson('/api/friends', array_filter([
            'user_id' => $bob->id,
            'note' => $note,
        ], fn ($value) => $value !== null))
            ->assertCreated()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('note', $stored);

        $this->assertDatabaseHas('friend_requests', [
            'sender_id' => $alice->id,
            'recipient_id' => $bob->id,
            'status' => 'pending',
            'note' => $stored,
        ]);
    })->with([
        'no note key' => [null, null],
        'blank note' => ['   ', null],
        'real note' => ['Hey Bob, it is Alice!', 'Hey Bob, it is Alice!'],
    ]);

    it('never allows a request to myself', function () {
        $alice = makeUser('alice');

        actingAsUser($alice)->postJson('/api/friends', ['user_id' => $alice->id])
            ->assertStatus(422);

        $this->assertDatabaseCount('friend_requests', 0);
    });

    it('rejects a request when already friends', function () {
        $alice = makeUser('alice');
        $bob = makeUser('bob');
        makeFriends($alice, $bob);

        actingAsUser($alice)->postJson('/api/friends', ['user_id' => $bob->id])
            ->assertStatus(422);
    });

    it('rejects a duplicate pending request from the sender', function () {
        $alice = makeUser('alice');
        $bob = makeUser('bob');

        actingAsUser($alice)->postJson('/api/friends', ['user_id' => $bob->id])->assertCreated();
        actingAsUser($alice)->postJson('/api/friends', ['user_id' => $bob->id])->assertStatus(422);

        $this->assertDatabaseCount('friend_requests', 1);
    });

    it('rejects a request when either side has blocked the other', function () {
        $alice = makeUser('alice');
        $bob = makeUser('bob');

        UserBlock::factory()->create(['blocker_id' => $bob->id, 'blocked_id' => $alice->id]);

        actingAsUser($alice)->postJson('/api/friends', ['user_id' => $bob->id])
            ->assertForbidden();

        $this->assertDatabaseCount('friend_requests', 0);
    });
});

describe('responding to friend requests', function () {
    it('accepts a request and creates a two-way friendship', function () {
        $alice = makeUser('alice');
        $bob = makeUser('bob');

        actingAsUser($alice)->postJson('/api/friends', ['user_id' => $bob->id])->assertCreated();
        $request = FriendRequest::firstOrFail();

        actingAsUser($bob)->postJson("/api/friends/{$request->id}/accept")
            ->assertOk()
            ->assertJsonPath('status', 'accepted');

        expect($alice->fresh()->isFriendsWith($bob->id))->toBeTrue()
            ->and($bob->fresh()->isFriendsWith($alice->id))->toBeTrue();

        $this->assertDatabaseCount('friendships', 2);
        $this->assertDatabaseHas('friend_requests', ['id' => $request->id, 'status' => 'accepted']);
    });

    it('declines a pending request', function () {
        $alice = makeUser('alice');
        $bob = makeUser('bob');

        actingAsUser($alice)->postJson('/api/friends', ['user_id' => $bob->id])->assertCreated();
        $request = FriendRequest::firstOrFail();

        actingAsUser($bob)->postJson("/api/friends/{$request->id}/reject")
            ->assertOk()
            ->assertJsonPath('status', 'rejected');

        expect($alice->fresh()->isFriendsWith($bob->id))->toBeFalse();
        $this->assertDatabaseCount('friendships', 0);
    });

    it('lets the sender cancel a pending request', function () {
        $alice = makeUser('alice');
        $bob = makeUser('bob');

        actingAsUser($alice)->postJson('/api/friends', ['user_id' => $bob->id])->assertCreated();
        $request = FriendRequest::firstOrFail();

        actingAsUser($alice)->deleteJson("/api/friends/{$request->id}")
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        $this->assertDatabaseHas('friend_requests', ['id' => $request->id, 'status' => 'cancelled']);
    });

    it('forbids acting on somebody elses request', function () {
        $alice = makeUser('alice');
        $bob = makeUser('bob');
        $eve = makeUser('eve');

        actingAsUser($alice)->postJson('/api/friends', ['user_id' => $bob->id])->assertCreated();
        $request = FriendRequest::firstOrFail();

        // The sender cannot accept their own outgoing request.
        actingAsUser($alice)->postJson("/api/friends/{$request->id}/accept")->assertForbidden();

        // The recipient cannot cancel it.
        actingAsUser($bob)->deleteJson("/api/friends/{$request->id}")->assertForbidden();

        // A stranger cannot touch it at all.
        actingAsUser($eve)->postJson("/api/friends/{$request->id}/accept")->assertForbidden();
        actingAsUser($eve)->deleteJson("/api/friends/{$request->id}")->assertForbidden();

        $this->assertDatabaseHas('friend_requests', ['id' => $request->id, 'status' => 'pending']);
    });
});

describe('find friends search', function () {
    it('excludes myself, friends, existing chats and pending requests in either direction', function () {
        $me = makeUser('me');
        $friend = makeUser('friend');
        $chatPartner = makeUser('chatter');
        $incoming = makeUser('incoming');
        $outgoing = makeUser('outgoing');
        $available = makeUser('available');

        makeFriends($me, $friend);

        createConversation($me, $chatPartner);

        FriendRequest::factory()->create(['sender_id' => $incoming->id, 'recipient_id' => $me->id]);
        FriendRequest::factory()->create(['sender_id' => $me->id, 'recipient_id' => $outgoing->id]);

        $ids = collect(actingAsUser($me)->getJson('/api/users?scope=find-friends')->assertOk()->json())
            ->pluck('id')
            ->all();

        expect($ids)->toContain($available->id)
            ->not->toContain($me->id)
            ->not->toContain($friend->id)
            ->not->toContain($chatPartner->id)
            ->not->toContain($incoming->id)
            ->not->toContain($outgoing->id);
    });

    it('still excludes users with a pending message request', function () {
        $me = makeUser('me');
        $stranger = makeUser('stranger');
        $conversation = createConversation($me, $stranger);

        MessageRequest::factory()->create([
            'sender_id' => $stranger->id,
            'recipient_id' => $me->id,
            'conversation_id' => $conversation->id,
            'status' => 'pending',
        ]);

        $ids = collect(actingAsUser($me)->getJson('/api/users?scope=find-friends')->json())
            ->pluck('id')
            ->all();

        expect($ids)->not->toContain($stranger->id);
    });

    it('searches by name and username', function () {
        makeUser('alice');
        $bob = makeUser('bob');

        actingAsUser($bob)->getJson('/api/users?scope=find-friends&q=ali')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.username', 'alice');
    });
});

describe('request badges', function () {
    it('reports unseen incoming requests and clears them when seen', function () {
        $alice = makeUser('alice');
        $bob = makeUser('bob');

        actingAsUser($alice)->postJson('/api/friends', ['user_id' => $bob->id])->assertCreated();

        $payload = actingAsUser($bob)->getJson('/api/friends')->assertOk()->json();

        expect($payload['counts']['incoming'])->toBe(1)
            ->and($payload['counts']['unseen_incoming'])->toBe(1)
            ->and($payload['incoming'][0]['unseen'])->toBeTrue();

        actingAsUser($bob)->postJson('/api/requests/seen')
            ->assertOk()
            ->assertJsonPath('counts.friend_unseen', 0);

        $after = actingAsUser($bob)->getJson('/api/friends')->json();

        expect($after['counts']['unseen_incoming'])->toBe(0)
            ->and($after['incoming'][0]['unseen'])->toBeFalse();

        // The request itself stays pending and can still be accepted.
        $requestId = $after['incoming'][0]['id'];
        actingAsUser($bob)->postJson("/api/friends/{$requestId}/accept")->assertOk();
    });
});
