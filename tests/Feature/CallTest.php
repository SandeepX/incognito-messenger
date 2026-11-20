<?php

use App\Events\CallEvent;
use App\Models\Call;
use App\Models\Message;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;

function callLegacyOnline($user): void
{
    $user->forceFill(['last_active_at' => now()])->save();
}

function callLegacyInvite($caller, $callee, string $tabId = 'caller-tab')
{
    return test()->actingAs($caller)->postJson('/api/calls/invite', [
        'user_id' => $callee->id,
        'tab_id' => $tabId,
    ]);
}

function callLegacyRinging($caller, $callee): Call
{
    test()->befriend($caller, $callee);
    callLegacyOnline($callee);

    $id = callLegacyInvite($caller, $callee)->assertCreated()->json('call.id');

    return Call::findOrFail($id);
}

function callLegacyActive($caller, $callee): Call
{
    $call = callLegacyRinging($caller, $callee);

    test()->actingAs($callee)
        ->postJson("/api/calls/{$call->id}/accept", ['tab_id' => 'callee-tab'])
        ->assertOk()
        ->assertJsonPath('call.status', 'active');

    return $call->fresh();
}

it('invites require friendship and respect the callee policy', function () {
    $alice = $this->userWithProfile('alice');
    $bob = $this->userWithProfile('bob');
    callLegacyOnline($bob);

    callLegacyInvite($alice, $bob)->assertForbidden();

    $this->befriend($alice, $bob);
    $bob->profile->update(['allow_calls' => 'nobody']);

    callLegacyInvite($alice, $bob)->assertForbidden();
    $this->assertDatabaseCount('calls', 0);
});

it('invite, ringing, and accept move the call to active', function () {
    Event::fake([CallEvent::class]);

    $alice = $this->userWithProfile('alice');
    $bob = $this->userWithProfile('bob');
    $call = callLegacyRinging($alice, $bob);

    $this->assertSame('ringing', $call->status);
    $this->assertSame('caller-tab', $call->caller_tab_id);

    Event::assertDispatched(CallEvent::class, function (CallEvent $event) use ($bob) {
        $channels = array_map(fn ($channel) => $channel->name, $event->broadcastOn());

        return $event->eventName === 'call:invite'
            && $event->call->callee_id === $bob->id
            && in_array("private-users.{$bob->id}", $channels, true);
    });

    $this->actingAs($bob)
        ->postJson("/api/calls/{$call->id}/ringing")
        ->assertOk();

    $this->assertNotNull($call->fresh()->ringing_at);
    Event::assertDispatched(CallEvent::class, fn (CallEvent $event) => $event->eventName === 'call:ringing');

    $this->actingAs($bob)
        ->postJson("/api/calls/{$call->id}/accept", ['tab_id' => 'callee-tab'])
        ->assertOk()
        ->assertJsonPath('call.status', 'active');

    $call->refresh();
    $this->assertSame('active', $call->status);
    $this->assertSame('callee-tab', $call->callee_tab_id);
    $this->assertNotNull($call->answered_at);

    Event::assertDispatched(CallEvent::class, function (CallEvent $event) {
        $channels = array_map(fn ($channel) => $channel->name, $event->broadcastOn());

        return $event->eventName === 'call:accept'
            && $event->extra['accepted_tab_id'] === 'callee-tab'
            && count($channels) === 2;
    });
});

it('busy and offline callees do not ring', function () {
    Event::fake([CallEvent::class]);

    $alice = $this->userWithProfile('alice');
    $bob = $this->userWithProfile('bob');
    $charlie = $this->userWithProfile('charlie');
    $this->befriend($alice, $bob);
    $this->befriend($bob, $charlie);
    callLegacyOnline($bob);

    Call::create([
        'caller_id' => $charlie->id,
        'callee_id' => $bob->id,
        'status' => 'active',
        'answered_at' => now(),
    ]);

    callLegacyInvite($alice, $bob)
        ->assertStatus(409)
        ->assertJsonPath('reason', 'busy')
        ->assertJsonPath('message', 'User is busy.');

    Event::assertDispatched(CallEvent::class, function (CallEvent $event) use ($alice) {
        return $event->eventName === 'call:busy'
            && $event->extra['reason'] === 'busy'
            && $event->broadcastOn()[0]->name === "private-users.{$alice->id}";
    });

    $bob->forceFill(['last_active_at' => now()->subMinutes(5)])->save();
    Call::where('callee_id', $bob->id)
        ->whereIn('status', ['ringing', 'active'])
        ->update(['status' => 'ended', 'ended_at' => now()]);

    callLegacyInvite($alice, $bob)
        ->assertStatus(409)
        ->assertJsonPath('reason', 'unavailable')
        ->assertJsonPath('message', 'User is unavailable.');

    $missed = Call::where('caller_id', $alice->id)->latest('id')->firstOrFail();
    $this->assertSame('missed', $missed->status);
    $this->assertDatabaseHas('messages', [
        'call_id' => $missed->id,
        'type' => 'call',
        'body' => 'Missed audio call',
    ]);
    $this->assertDatabaseHas('notifications', [
        'user_id' => $bob->id,
        'type' => 'missed_call',
    ]);
});

it('decline and caller cancel write history messages', function () {
    Event::fake([CallEvent::class]);

    $alice = $this->userWithProfile('alice');
    $bob = $this->userWithProfile('bob');

    $declined = callLegacyRinging($alice, $bob);
    $this->actingAs($bob)->postJson("/api/calls/{$declined->id}/reject")->assertOk();

    $this->assertSame('declined', $declined->fresh()->status);
    $this->assertDatabaseHas('messages', [
        'call_id' => $declined->id,
        'body' => 'Declined audio call',
    ]);
    Event::assertDispatched(CallEvent::class, fn (CallEvent $event) => $event->eventName === 'call:reject');

    $cancelled = callLegacyRinging($alice, $bob);
    $this->actingAs($alice)->postJson("/api/calls/{$cancelled->id}/cancel")->assertOk();

    $this->assertSame('missed', $cancelled->fresh()->status);
    $this->assertDatabaseHas('messages', [
        'call_id' => $cancelled->id,
        'body' => 'Missed audio call',
    ]);
    Event::assertDispatched(CallEvent::class, fn (CallEvent $event) => $event->eventName === 'call:cancel');
});

it('signaling is only relayed for active calls and to the peer tab', function () {
    Event::fake([CallEvent::class]);

    $alice = $this->userWithProfile('alice');
    $bob = $this->userWithProfile('bob');
    $eve = $this->userWithProfile('eve');
    $call = callLegacyRinging($alice, $bob);

    $this->actingAs($bob)
        ->postJson("/api/calls/{$call->id}/signal", [
            'type' => 'offer',
            'payload' => ['type' => 'offer', 'sdp' => base64_encode('v=0')],
        ])
        ->assertStatus(409);

    $this->actingAs($bob)
        ->postJson("/api/calls/{$call->id}/accept", ['tab_id' => 'callee-tab'])
        ->assertOk();

    $this->actingAs($eve)
        ->postJson("/api/calls/{$call->id}/signal", [
            'type' => 'offer',
            'payload' => ['type' => 'offer', 'sdp' => base64_encode('v=0')],
        ])
        ->assertForbidden();

    $this->actingAs($alice)
        ->postJson("/api/calls/{$call->id}/signal", [
            'type' => 'offer',
            'payload' => ['type' => 'offer', 'sdp' => base64_encode('v=0')],
        ])
        ->assertOk();

    Event::assertDispatched(CallEvent::class, function (CallEvent $event) use ($bob) {
        return $event->eventName === 'call:offer'
            && $event->extra['to_tab_id'] === 'callee-tab'
            && $event->extra['signal']['sdp'] === base64_encode('v=0')
            && $event->broadcastOn()[0]->name === "private-users.{$bob->id}";
    });
});

it('ending an active call records duration, history, and pushes to both sides', function () {
    Event::fake([CallEvent::class]);

    $alice = $this->userWithProfile('alice');
    $bob = $this->userWithProfile('bob');
    $call = callLegacyActive($alice, $bob);

    $call->forceFill(['answered_at' => now()])->save();
    $this->travel(65)->seconds();

    $this->actingAs($alice)
        ->postJson("/api/calls/{$call->id}/end")
        ->assertOk()
        ->assertJsonPath('call.status', 'ended');

    $call->refresh();
    $this->assertSame('ended', $call->status);
    $this->assertSame(65, $call->duration_seconds);

    $message = Message::where('call_id', $call->id)->firstOrFail();
    $this->assertSame('call', $message->type);
    $this->assertSame('Audio call, 01:05', $message->body);

    Event::assertDispatched(CallEvent::class, function (CallEvent $event) use ($alice, $bob) {
        $channels = array_map(fn ($channel) => $channel->name, $event->broadcastOn());

        return $event->eventName === 'call:end'
            && in_array("private-users.{$alice->id}", $channels, true)
            && in_array("private-users.{$bob->id}", $channels, true);
    });

    $viewer = $bob->fresh()->load('profile');
    $payload = $message->payload($bob->id, $viewer);
    $this->assertSame('call', $payload['type']);
    $this->assertSame('Audio call, 01:05', $payload['preview']);
    $this->assertSame([], $payload['seen_by']);
    $this->assertSame('', $payload['body']);

    $this->actingAs($bob)->postJson("/api/messages/{$message->id}/seen")->assertStatus(422);
    $this->actingAs($alice)->postJson("/api/messages/{$message->id}/unsend", ['mode' => 'everyone'])->assertStatus(422);

    $list = collect($this->actingAs($bob)->getJson('/api/conversations')->assertOk()->json());
    $row = $list->firstWhere('id', $call->conversation_id);
    $this->assertSame('call', $row['last_message']['type']);
    $this->assertSame('Audio call, 01:05', $row['last_message']['preview']);
});

it('messenger and settings pages render the call UI', function () {
    $alice = $this->userWithProfile('alice');

    $this->actingAs($alice)->get('/')
        ->assertOk()
        ->assertSee('chat-call-btn', false)
        ->assertSee('call-overlay', false);

    $this->actingAs($alice)->get('/profile')
        ->assertOk()
        ->assertSee('section-calls', false)
        ->assertSee('test-mic-btn', false)
        ->assertSee('call-overlay', false);
});

it('blocked users cannot call each other', function () {
    $alice = $this->userWithProfile('alice');
    $bob = $this->userWithProfile('bob');
    $this->befriend($alice, $bob);
    callLegacyOnline($bob);

    $alice->blockedUsers()->attach($bob->id);

    callLegacyInvite($alice, $bob)->assertForbidden();
    $this->assertDatabaseCount('calls', 0);
});

it("closing another tab does not end the owner's active call", function () {
    $alice = $this->userWithProfile('alice');
    $bob = $this->userWithProfile('bob');
    $call = callLegacyActive($alice, $bob);

    $this->actingAs($alice)
        ->postJson('/api/presence/disconnect', ['tab_id' => 'other-tab'])
        ->assertOk();

    $this->assertSame('active', $call->fresh()->status);

    $this->actingAs($alice)
        ->postJson('/api/presence/disconnect', ['tab_id' => 'caller-tab'])
        ->assertOk();

    $this->assertSame('ended', $call->fresh()->status);
});

it('closing one ringing tab keeps the other tabs ringing', function () {
    Event::fake([CallEvent::class]);

    $alice = $this->userWithProfile('alice');
    $bob = $this->userWithProfile('bob');
    $call = callLegacyRinging($alice, $bob);

    foreach (['tab-a', 'tab-b'] as $tab) {
        $this->actingAs($bob)
            ->postJson("/api/calls/{$call->id}/ringing", ['tab_id' => $tab])
            ->assertOk();
    }

    $this->assertSame(['tab-a', 'tab-b'], $call->fresh()->ringing_tabs);

    $this->actingAs($bob)
        ->postJson("/api/calls/{$call->id}/leave", ['tab_id' => 'tab-a'])
        ->assertOk();

    $call->refresh();
    $this->assertSame('ringing', $call->status);
    $this->assertSame(['tab-b'], $call->ringing_tabs);

    $this->actingAs($bob)
        ->postJson("/api/calls/{$call->id}/leave", ['tab_id' => 'tab-b'])
        ->assertOk();

    $this->assertSame('missed', $call->fresh()->status);
    $this->assertDatabaseHas('messages', [
        'call_id' => $call->id,
        'body' => 'Missed audio call',
    ]);
    Event::assertDispatched(CallEvent::class, fn (CallEvent $event) => $event->eventName === 'call:end'
        && $event->extra['reason'] === 'disconnect');
});

it('presence disconnect removes only the closing ringing tab', function () {
    $alice = $this->userWithProfile('alice');
    $bob = $this->userWithProfile('bob');
    $call = callLegacyRinging($alice, $bob);

    foreach (['tab-a', 'tab-b'] as $tab) {
        $this->actingAs($bob)
            ->postJson("/api/calls/{$call->id}/ringing", ['tab_id' => $tab])
            ->assertOk();
    }

    $this->actingAs($bob)
        ->postJson('/api/presence/disconnect', ['tab_id' => 'tab-a'])
        ->assertOk();

    $this->assertSame('ringing', $call->fresh()->status);

    $this->actingAs($bob)
        ->postJson('/api/presence/disconnect', ['tab_id' => 'tab-b'])
        ->assertOk();

    $this->assertSame('missed', $call->fresh()->status);
});

it('unanswered rings time out and both clients get call end', function () {
    Event::fake([CallEvent::class]);

    $alice = $this->userWithProfile('alice');
    $bob = $this->userWithProfile('bob');
    $call = callLegacyRinging($alice, $bob);

    $this->travel(31)->seconds();

    $this->actingAs($bob)
        ->postJson("/api/calls/{$call->id}/timeout")
        ->assertOk();

    $call->refresh();
    $this->assertSame('missed', $call->status);
    $this->assertSame('timeout', $call->end_reason);
    $this->assertDatabaseHas('messages', [
        'call_id' => $call->id,
        'body' => 'Missed audio call',
    ]);
    Event::assertDispatched(CallEvent::class, function (CallEvent $event) {
        $channels = array_map(fn ($channel) => $channel->name, $event->broadcastOn());

        return $event->eventName === 'call:end'
            && $event->extra['reason'] === 'timeout'
            && count($channels) === 2;
    });
});

it('only participants can authorize call and user channels', function () {
    Event::fake([CallEvent::class]);

    $alice = $this->userWithProfile('alice');
    $bob = $this->userWithProfile('bob');
    $eve = $this->userWithProfile('eve');
    $call = callLegacyRinging($alice, $bob);

    Event::assertDispatched(CallEvent::class, function (CallEvent $event) use ($bob) {
        $channels = array_map(fn ($channel) => $channel->name, $event->broadcastOn());

        return $event->eventName === 'call:invite'
            && $channels === ["private-users.{$bob->id}"];
    });

    $channels = Broadcast::driver()->getChannels();
    $userRule = $channels['users.{id}'];
    $callRule = $channels['call.{callId}'];

    $this->assertTrue($userRule($alice, (string) $alice->id));
    $this->assertFalse($userRule($eve, (string) $alice->id));

    $this->assertTrue($callRule($alice, (string) $call->id));
    $this->assertTrue($callRule($bob, (string) $call->id));
    $this->assertFalse($callRule($eve, (string) $call->id));
});

it('busy endpoint performs server-side transition and writes history', function () {
    Event::fake([CallEvent::class]);

    $alice = $this->userWithProfile('alice');
    $bob = $this->userWithProfile('bob');
    $call = callLegacyRinging($alice, $bob);

    $eve = $this->userWithProfile('eve');
    $this->actingAs($eve)
        ->postJson("/api/calls/{$call->id}/busy")
        ->assertForbidden();

    $this->actingAs($alice)
        ->postJson("/api/calls/{$call->id}/busy")
        ->assertOk()
        ->assertJsonPath('call.status', 'busy');

    $call->refresh();
    $this->assertSame('busy', $call->status);
    Event::assertDispatched(CallEvent::class, function (CallEvent $event) use ($alice) {
        return $event->eventName === 'call:busy'
            && $event->broadcastOn()[0]->name === "private-users.{$alice->id}";
    });
});
