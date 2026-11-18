<?php

namespace Tests\Feature;

use App\Events\PresenceUpdated;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use ReflectionProperty;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

class PresenceTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_heartbeat_and_disconnect_update_last_active_and_broadcast_to_contacts(): void
    {
        Event::fake([PresenceUpdated::class]);

        $alice = $this->userWithProfile('alice');
        $bob = $this->userWithProfile('bob');
        $stranger = $this->userWithProfile('stranger');
        $this->befriend($alice, $bob);

        $this->actingAs($alice)->postJson('/api/presence/heartbeat')
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertNotNull($alice->fresh()->last_active_at);
        $this->assertTrue($this->presenceWasBroadcastTo($alice, $bob, true));
        $this->assertFalse($this->presenceWasBroadcastTo($alice, $stranger, true));

        $this->actingAs($alice)->postJson('/api/presence/disconnect')->assertOk();
        $this->assertTrue($this->presenceWasBroadcastTo($alice, $bob, false));
    }

    public function test_hidden_active_status_is_not_exposed_in_rest_or_presence_channel(): void
    {
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

        $member = $this->onlineChannelPayload($alice->fresh());
        $this->assertFalse($member['show_active_status']);
        $this->assertNull($member['last_active_at']);
    }

    public function test_visible_active_status_includes_the_timestamp(): void
    {
        $alice = $this->userWithProfile('alice');
        $bob = $this->userWithProfile('bob');
        $this->befriend($alice, $bob);

        $this->actingAs($alice)->postJson('/api/presence/heartbeat')->assertOk();

        $presence = collect($this->actingAs($bob)->getJson('/api/presence')->assertOk()->json())
            ->firstWhere('id', $alice->id);

        $this->assertTrue($presence['show_active_status']);
        $this->assertNotNull($presence['last_active_at']);
        $this->assertNotNull($this->onlineChannelPayload($alice->fresh())['last_active_at']);
    }

    private function presenceWasBroadcastTo($alice, $recipient, bool $online): bool
    {
        return Event::dispatched(PresenceUpdated::class, function (PresenceUpdated $event) use ($alice, $recipient, $online) {
            if ($event->user->id !== $alice->id || $event->online !== $online) {
                return false;
            }

            $channels = array_map(fn ($channel) => $channel->name, $event->broadcastOn());

            return in_array('private-user.'.$recipient->id, $channels, true);
        })->isNotEmpty();
    }

    private function onlineChannelPayload($user): array
    {
        $property = new ReflectionProperty(Broadcaster::class, 'channels');
        $property->setAccessible(true);
        $callback = $property->getValue(Broadcast::driver())['online'];

        return $callback($user);
    }
}
