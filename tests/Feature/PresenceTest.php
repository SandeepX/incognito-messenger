<?php

use App\Events\PresenceUpdated;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;

function presenceTestHelperWasBroadcastTo($alice, $recipient, bool $online): bool
{
    return Event::dispatched(PresenceUpdated::class, function (PresenceUpdated $event) use ($alice, $recipient, $online) {
        if ($event->user->id !== $alice->id || $event->online !== $online) {
            return false;
        }

        $channels = array_map(fn ($channel) => $channel->name, $event->broadcastOn());

        return in_array('private-user.'.$recipient->id, $channels, true);
    })->isNotEmpty();
}

function presenceTestHelperOnlineChannelPayload($user): array
{
    $property = new ReflectionProperty(Broadcaster::class, 'channels');
    $property->setAccessible(true);
    $callback = $property->getValue(Broadcast::driver())['online'];

    return $callback($user);
}

it('updates last active and broadcasts heartbeat and disconnect to contacts', function () {
    Event::fake([PresenceUpdated::class]);

    $alice = $this->userWithProfile('alice');
    $bob = $this->userWithProfile('bob');
    $stranger = $this->userWithProfile('stranger');
    $this->befriend($alice, $bob);

    $this->actingAs($alice)->postJson('/api/presence/heartbeat')
        ->assertOk()
        ->assertJsonPath('ok', true);

    $this->assertNotNull($alice->fresh()->last_active_at);
    $this->assertTrue(presenceTestHelperWasBroadcastTo($alice, $bob, true));
    $this->assertFalse(presenceTestHelperWasBroadcastTo($alice, $stranger, true));

    $this->actingAs($alice)->postJson('/api/presence/disconnect')->assertOk();
    $this->assertTrue(presenceTestHelperWasBroadcastTo($alice, $bob, false));
});

it('does not expose hidden active status in REST or the presence channel', function () {
    $alice = $this->userWithProfile('alice');
    $bob = $this->userWithProfile('bob');
    $this->befriend($alice, $bob);

    $alice->profile->update(['show_active_status' => false]);
    $this->actingAs($alice)->postJson('/api/presence/heartbeat')->assertOk();

    // The timestamp is still stored; only other people cannot read it.
    $this->assertNotNull($alice->fresh()->last_active_at);

    $presence = collect($this->actingAs($bob)->getJson('/api/presence')->assertOk()->json())
        ->firstWhere('id', $alice->id);

    $this->assertNotNull($presence);
    $this->assertFalse($presence['show_active_status']);
    $this->assertNull($presence['last_active_at']);

    $member = presenceTestHelperOnlineChannelPayload($alice->fresh());
    $this->assertFalse($member['show_active_status']);
    $this->assertNull($member['last_active_at']);
});

it('includes the timestamp for visible active status', function () {
    $alice = $this->userWithProfile('alice');
    $bob = $this->userWithProfile('bob');
    $this->befriend($alice, $bob);

    $this->actingAs($alice)->postJson('/api/presence/heartbeat')->assertOk();

    $presence = collect($this->actingAs($bob)->getJson('/api/presence')->assertOk()->json())
        ->firstWhere('id', $alice->id);

    $this->assertTrue($presence['show_active_status']);
    $this->assertNotNull($presence['last_active_at']);
    $this->assertNotNull(presenceTestHelperOnlineChannelPayload($alice->fresh())['last_active_at']);
});
