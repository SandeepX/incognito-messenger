<?php

namespace Tests\Feature;

use App\Events\CallEvent;
use App\Models\Call;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

class CallTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private function online($user): void
    {
        $user->forceFill(['last_active_at' => now()])->save();
    }

    private function invite($caller, $callee, string $tabId = 'caller-tab')
    {
        return $this->actingAs($caller)->postJson('/api/calls/invite', [
            'user_id' => $callee->id,
            'tab_id' => $tabId,
        ]);
    }

    private function ringingCall($caller, $callee): Call
    {
        $this->befriend($caller, $callee);
        $this->online($callee);

        $id = $this->invite($caller, $callee)->assertCreated()->json('call.id');

        return Call::findOrFail($id);
    }

    private function activeCall($caller, $callee): Call
    {
        $call = $this->ringingCall($caller, $callee);

        $this->actingAs($callee)
            ->postJson("/api/calls/{$call->id}/accept", ['tab_id' => 'callee-tab'])
            ->assertOk()
            ->assertJsonPath('call.status', 'active');

        return $call->fresh();
    }

    public function test_invites_require_friendship_and_respect_the_callee_policy(): void
    {
        $alice = $this->userWithProfile('alice');
        $bob = $this->userWithProfile('bob');
        $this->online($bob);

        $this->invite($alice, $bob)->assertForbidden();

        $this->befriend($alice, $bob);
        $bob->profile->update(['allow_calls' => 'nobody']);

        $this->invite($alice, $bob)->assertForbidden();
        $this->assertDatabaseCount('calls', 0);
    }

    public function test_invite_ringing_and_accept_move_the_call_to_active(): void
    {
        Event::fake([CallEvent::class]);

        $alice = $this->userWithProfile('alice');
        $bob = $this->userWithProfile('bob');
        $call = $this->ringingCall($alice, $bob);

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
    }

    public function test_busy_and_offline_callees_do_not_ring(): void
    {
        Event::fake([CallEvent::class]);

        $alice = $this->userWithProfile('alice');
        $bob = $this->userWithProfile('bob');
        $charlie = $this->userWithProfile('charlie');
        $this->befriend($alice, $bob);
        $this->befriend($bob, $charlie);
        $this->online($bob);

        Call::create([
            'caller_id' => $charlie->id,
            'callee_id' => $bob->id,
            'status' => 'active',
            'answered_at' => now(),
        ]);

        $this->invite($alice, $bob)
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

        $this->invite($alice, $bob)
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
    }

    public function test_decline_and_caller_cancel_write_history_messages(): void
    {
        Event::fake([CallEvent::class]);

        $alice = $this->userWithProfile('alice');
        $bob = $this->userWithProfile('bob');

        $declined = $this->ringingCall($alice, $bob);
        $this->actingAs($bob)->postJson("/api/calls/{$declined->id}/reject")->assertOk();

        $this->assertSame('declined', $declined->fresh()->status);
        $this->assertDatabaseHas('messages', [
            'call_id' => $declined->id,
            'body' => 'Declined audio call',
        ]);
        Event::assertDispatched(CallEvent::class, fn (CallEvent $event) => $event->eventName === 'call:reject');

        $cancelled = $this->ringingCall($alice, $bob);
        $this->actingAs($alice)->postJson("/api/calls/{$cancelled->id}/cancel")->assertOk();

        $this->assertSame('missed', $cancelled->fresh()->status);
        $this->assertDatabaseHas('messages', [
            'call_id' => $cancelled->id,
            'body' => 'Missed audio call',
        ]);
        Event::assertDispatched(CallEvent::class, fn (CallEvent $event) => $event->eventName === 'call:cancel');
    }

    public function test_signaling_is_only_relayed_for_active_calls_and_to_the_peer_tab(): void
    {
        Event::fake([CallEvent::class]);

        $alice = $this->userWithProfile('alice');
        $bob = $this->userWithProfile('bob');
        $eve = $this->userWithProfile('eve');
        $call = $this->ringingCall($alice, $bob);

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
    }

    public function test_ending_an_active_call_records_duration_history_and_pushes_to_both_sides(): void
    {
        Event::fake([CallEvent::class]);

        $alice = $this->userWithProfile('alice');
        $bob = $this->userWithProfile('bob');
        $call = $this->activeCall($alice, $bob);

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

        // Call history is a system row: it never creates read receipts or
        // unread badges, and it cannot be unsent.
        $this->actingAs($bob)->postJson("/api/messages/{$message->id}/seen")->assertStatus(422);
        $this->actingAs($alice)->postJson("/api/messages/{$message->id}/unsend", ['mode' => 'everyone'])->assertStatus(422);

        $list = collect($this->actingAs($bob)->getJson('/api/conversations')->assertOk()->json());
        $row = $list->firstWhere('id', $call->conversation_id);
        $this->assertSame('call', $row['last_message']['type']);
        $this->assertSame('Audio call, 01:05', $row['last_message']['preview']);
    }

    public function test_messenger_and_settings_pages_render_the_call_ui(): void
    {
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
    }

    public function test_blocked_users_cannot_call_each_other(): void
    {
        $alice = $this->userWithProfile('alice');
        $bob = $this->userWithProfile('bob');
        $this->befriend($alice, $bob);
        $this->online($bob);

        $alice->blockedUsers()->attach($bob->id);

        $this->invite($alice, $bob)->assertForbidden();
        $this->assertDatabaseCount('calls', 0);
    }

    public function test_closing_another_tab_does_not_end_the_owners_active_call(): void
    {
        $alice = $this->userWithProfile('alice');
        $bob = $this->userWithProfile('bob');
        $call = $this->activeCall($alice, $bob);

        $this->actingAs($alice)
            ->postJson('/api/presence/disconnect', ['tab_id' => 'other-tab'])
            ->assertOk();

        $this->assertSame('active', $call->fresh()->status);

        $this->actingAs($alice)
            ->postJson('/api/presence/disconnect', ['tab_id' => 'caller-tab'])
            ->assertOk();

        $this->assertSame('ended', $call->fresh()->status);
    }

    public function test_closing_one_ringing_tab_keeps_the_other_tabs_ringing(): void
    {
        Event::fake([CallEvent::class]);

        $alice = $this->userWithProfile('alice');
        $bob = $this->userWithProfile('bob');
        $call = $this->ringingCall($alice, $bob);

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

        // Closing the last ringing tab ends the call as missed.
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
    }

    public function test_presence_disconnect_removes_only_the_closing_ringing_tab(): void
    {
        $alice = $this->userWithProfile('alice');
        $bob = $this->userWithProfile('bob');
        $call = $this->ringingCall($alice, $bob);

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
    }

    public function test_unanswered_ring_times_out_and_both_clients_get_call_end(): void
    {
        Event::fake([CallEvent::class]);

        $alice = $this->userWithProfile('alice');
        $bob = $this->userWithProfile('bob');
        $call = $this->ringingCall($alice, $bob);

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
    }

    public function test_only_participants_can_authorize_call_and_user_channels(): void
    {
        Event::fake([CallEvent::class]);

        $alice = $this->userWithProfile('alice');
        $bob = $this->userWithProfile('bob');
        $eve = $this->userWithProfile('eve');
        $call = $this->ringingCall($alice, $bob);

        // Call signaling uses private-users.{id}, one channel per participant.
        Event::assertDispatched(CallEvent::class, function (CallEvent $event) use ($bob) {
            $channels = array_map(fn ($channel) => $channel->name, $event->broadcastOn());

            return $event->eventName === 'call:invite'
                && $channels === ["private-users.{$bob->id}"];
        });

        // The server authorizes every /broadcasting/auth request against the
        // route callbacks: users.* is self-only, call.* is participant-only.
        // Tests use the null broadcaster, so inspect the registered callbacks
        // directly instead of hitting /broadcasting/auth over HTTP.
        $channels = \Illuminate\Support\Facades\Broadcast::driver()->getChannels();
        $userRule = $channels['users.{id}'];
        $callRule = $channels['call.{callId}'];

        $this->assertTrue($userRule($alice, (string) $alice->id));
        $this->assertFalse($userRule($eve, (string) $alice->id));

        $this->assertTrue($callRule($alice, (string) $call->id));
        $this->assertTrue($callRule($bob, (string) $call->id));
        $this->assertFalse($callRule($eve, (string) $call->id));
    }

    public function test_busy_endpoint_server_side_transition_and_history(): void
    {
        Event::fake([CallEvent::class]);

        $alice = $this->userWithProfile('alice');
        $bob = $this->userWithProfile('bob');
        $call = $this->ringingCall($alice, $bob);

        // Only a participant may call the endpoint; a stranger is rejected.
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
    }
}
