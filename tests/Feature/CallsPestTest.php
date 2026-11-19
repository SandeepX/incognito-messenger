<?php

use App\Events\CallEvent;
use App\Models\Call;
use App\Models\Message;
use App\Support\UnreadCounter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

afterEach(function () {
    Carbon::setTestNow();
});

function goOnline($user): void
{
    $user->forceFill(['last_active_at' => now()])->save();
}

function inviteCall($caller, $callee, string $tab = 'caller-tab')
{
    return test()->actingAs($caller)->postJson('/api/calls/invite', [
        'user_id' => $callee->id,
        'tab_id' => $tab,
    ]);
}

function ringingCall($caller, $callee): Call
{
    makeFriends($caller, $callee);
    goOnline($callee);

    $id = inviteCall($caller, $callee)->assertCreated()->json('call.id');

    return Call::findOrFail($id);
}

function activeCall($caller, $callee): Call
{
    $call = ringingCall($caller, $callee);

    test()->actingAs($callee)
        ->postJson("/api/calls/{$call->id}/accept", ['tab_id' => 'callee-tab'])
        ->assertOk();

    return $call->fresh();
}

describe('inviting', function () {
    it('rings a friend and broadcasts only to the callee', function () {
        Event::fake([CallEvent::class]);

        $alice = makeUser('alice');
        $bob = makeUser('bob');
        makeFriends($alice, $bob);
        goOnline($bob);

        inviteCall($alice, $bob)
            ->assertCreated()
            ->assertJsonPath('call.status', 'ringing')
            ->assertJsonPath('call.caller_id', $alice->id)
            ->assertJsonPath('call.callee_id', $bob->id);

        $this->assertDatabaseHas('calls', [
            'caller_id' => $alice->id,
            'callee_id' => $bob->id,
            'status' => 'ringing',
        ]);

        Event::assertDispatched(CallEvent::class, function (CallEvent $event) use ($bob) {
            $channels = array_map(fn ($channel) => $channel->name, $event->broadcastOn());

            return $event->eventName === 'call:invite'
                && $channels === ["private-users.{$bob->id}"];
        });
    });

    it('rejects non-friends', function () {
        $alice = makeUser('alice');
        $bob = makeUser('bob');
        goOnline($bob);

        inviteCall($alice, $bob)->assertForbidden();

        $this->assertDatabaseCount('calls', 0);
    });

    it('rejects blocked users', function () {
        $alice = makeUser('alice');
        $bob = makeUser('bob');
        makeFriends($alice, $bob);
        goOnline($bob);

        $alice->blockedUsers()->attach($bob->id);

        inviteCall($alice, $bob)->assertForbidden();
        $this->assertDatabaseCount('calls', 0);
    });

    it('rejects a callee whose allow-calls policy is nobody', function () {
        $alice = makeUser('alice');
        $bob = makeUser('bob', [], ['allow_calls' => 'nobody']);
        makeFriends($alice, $bob);
        goOnline($bob);

        inviteCall($alice, $bob)->assertForbidden();
        $this->assertDatabaseCount('calls', 0);
    });

    it('returns busy when the callee is already in a call', function () {
        Event::fake([CallEvent::class]);

        $alice = makeUser('alice');
        $bob = makeUser('bob');
        $charlie = makeUser('charlie');
        makeFriends($alice, $bob);
        makeFriends($bob, $charlie);
        goOnline($bob);

        Call::factory()->active()->create([
            'caller_id' => $charlie->id,
            'callee_id' => $bob->id,
        ]);

        inviteCall($alice, $bob)
            ->assertStatus(409)
            ->assertJsonPath('reason', 'busy')
            ->assertJsonPath('message', 'User is busy.');

        Event::assertDispatched(CallEvent::class, function (CallEvent $event) use ($alice) {
            return $event->eventName === 'call:busy'
                && $event->broadcastOn()[0]->name === "private-users.{$alice->id}";
        });
    });

    it('does not let a user start two calls at once', function () {
        $alice = makeUser('alice');
        $bob = makeUser('bob');
        $charlie = makeUser('charlie');
        makeFriends($alice, $bob);
        makeFriends($alice, $charlie);
        goOnline($bob);
        goOnline($charlie);

        ringingCall($alice, $bob);

        inviteCall($alice, $charlie)
            ->assertStatus(409)
            ->assertJsonPath('reason', 'caller_busy');

        expect(Call::where('caller_id', $alice->id)->count())->toBe(1);
    });

    it('rate limits repeated invitations', function () {
        $alice = makeUser('alice');

        for ($i = 0; $i < 6; $i++) {
            actingAsUser($alice)->postJson('/api/calls/invite', [
                'user_id' => 999999,
                'tab_id' => 'tab',
            ])->assertStatus(422);
        }

        actingAsUser($alice)->postJson('/api/calls/invite', [
            'user_id' => 999999,
            'tab_id' => 'tab',
        ])->assertStatus(429);
    });
});

describe('signaling authorization', function () {
    it('forbids outsiders from sending offers, answers or ice candidates', function (string $type) {
        $alice = makeUser('alice');
        $bob = makeUser('bob');
        $eve = makeUser('eve');
        $call = activeCall($alice, $bob);

        actingAsUser($eve)->postJson("/api/calls/{$call->id}/signal", [
            'type' => $type,
            'payload' => ['type' => $type, 'sdp' => base64_encode('v=0')],
        ])->assertForbidden();
    })->with(['offer', 'answer', 'ice-candidate']);

    it('relays signaling between the two participants only', function (string $type, array $payload) {
        Event::fake([CallEvent::class]);

        $alice = makeUser('alice');
        $bob = makeUser('bob');
        $call = activeCall($alice, $bob);

        actingAsUser($alice)->postJson("/api/calls/{$call->id}/signal", [
            'type' => $type,
            'payload' => $payload,
        ])->assertOk();

        Event::assertDispatched(CallEvent::class, function (CallEvent $event) use ($type, $bob) {
            return $event->eventName === 'call:'.$type
                && $event->broadcastOn()[0]->name === "private-users.{$bob->id}";
        });
    })->with([
        'offer' => ['offer', ['type' => 'offer', 'sdp' => base64_encode('v=0')]],
        'answer' => ['answer', ['type' => 'answer', 'sdp' => base64_encode('v=0')]],
        'ice candidate' => ['ice-candidate', ['candidate' => 'candidate:1 1 UDP 1 127.0.0.1 5000 typ host']],
    ]);

    it('rejects signaling on a call that is not active yet', function () {
        $alice = makeUser('alice');
        $bob = makeUser('bob');
        $call = ringingCall($alice, $bob);

        actingAsUser($alice)->postJson("/api/calls/{$call->id}/signal", [
            'type' => 'offer',
            'payload' => ['type' => 'offer', 'sdp' => base64_encode('v=0')],
        ])->assertStatus(409);
    });
});

describe('call state transitions', function () {
    it('accepts a ringing call and broadcasts to both participants', function () {
        Event::fake([CallEvent::class]);

        $alice = makeUser('alice');
        $bob = makeUser('bob');
        $call = ringingCall($alice, $bob);

        actingAsUser($bob)
            ->postJson("/api/calls/{$call->id}/accept", ['tab_id' => 'callee-tab'])
            ->assertOk()
            ->assertJsonPath('call.status', 'active');

        $call->refresh();

        expect($call->status)->toBe('active')
            ->and($call->callee_tab_id)->toBe('callee-tab')
            ->and($call->answered_at)->not->toBeNull();

        Event::assertDispatched(CallEvent::class, function (CallEvent $event) use ($alice, $bob) {
            $channels = array_map(fn ($channel) => $channel->name, $event->broadcastOn());

            return $event->eventName === 'call:accept'
                && in_array("private-users.{$alice->id}", $channels, true)
                && in_array("private-users.{$bob->id}", $channels, true);
        });
    });

    it('rejects a ringing call and writes declined history', function () {
        $alice = makeUser('alice');
        $bob = makeUser('bob');
        $call = ringingCall($alice, $bob);

        actingAsUser($bob)->postJson("/api/calls/{$call->id}/reject")
            ->assertOk()
            ->assertJsonPath('call.status', 'declined');

        $this->assertDatabaseHas('messages', [
            'call_id' => $call->id,
            'type' => 'call',
            'body' => 'Declined audio call',
        ]);
    });

    it('lets the caller cancel a ringing call as missed', function () {
        $alice = makeUser('alice');
        $bob = makeUser('bob');
        $call = ringingCall($alice, $bob);

        actingAsUser($alice)->postJson("/api/calls/{$call->id}/cancel")
            ->assertOk()
            ->assertJsonPath('call.status', 'missed');

        expect($call->fresh()->end_reason)->toBe('cancelled');
        $this->assertDatabaseHas('messages', [
            'call_id' => $call->id,
            'body' => 'Missed audio call',
        ]);
    });

    it('ends an active call and records its duration', function () {
        $alice = makeUser('alice');
        $bob = makeUser('bob');
        $call = activeCall($alice, $bob);

        $call->forceFill(['answered_at' => now()->subSeconds(65)])->save();

        actingAsUser($alice)
            ->postJson("/api/calls/{$call->id}/end")
            ->assertOk()
            ->assertJsonPath('call.status', 'ended');

        $call->refresh();

        expect($call->status)->toBe('ended')
            ->and($call->duration_seconds)->toBe(65);

        $this->assertDatabaseHas('messages', [
            'call_id' => $call->id,
            'type' => 'call',
            'body' => 'Audio call, 01:05',
        ]);
    });

    it('records a missed call after the 30 second ring timeout', function () {
        Carbon::setTestNow('2026-11-19 09:00:00');

        $alice = makeUser('alice');
        $bob = makeUser('bob');
        $call = ringingCall($alice, $bob);

        Carbon::setTestNow('2026-11-19 09:00:31');

        actingAsUser($bob)->postJson("/api/calls/{$call->id}/timeout")
            ->assertOk()
            ->assertJsonPath('call.status', 'missed');

        $call->refresh();

        expect($call->status)->toBe('missed')
            ->and($call->end_reason)->toBe('timeout');

        $this->assertDatabaseHas('messages', [
            'call_id' => $call->id,
            'body' => 'Missed audio call',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $bob->id,
            'type' => 'missed_call',
        ]);
    });
});

describe('call history system messages', function () {
    it('does not create unread counts or read receipts for completed calls', function () {
        $alice = makeUser('alice');
        $bob = makeUser('bob');
        $call = activeCall($alice, $bob);
        $call->forceFill(['answered_at' => now()->subSeconds(30)])->save();

        actingAsUser($alice)->postJson("/api/calls/{$call->id}/end")->assertOk();

        $message = Message::where('call_id', $call->id)->firstOrFail();

        expect(UnreadCounter::for($bob->id))->toBe([])
            ->and($message->reads()->count())->toBe(0);

        actingAsUser($bob)->postJson("/api/messages/{$message->id}/seen")->assertStatus(422);
    });

    it('does not create unread counts for declined calls', function () {
        $alice = makeUser('alice');
        $bob = makeUser('bob');
        $call = ringingCall($alice, $bob);

        actingAsUser($bob)->postJson("/api/calls/{$call->id}/reject")->assertOk();

        expect(UnreadCounter::for($alice->id))->toBe([])
            ->and(UnreadCounter::for($bob->id))->toBe([]);
    });
});
