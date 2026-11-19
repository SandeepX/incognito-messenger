<?php

use App\Events\MessageSeen;
use App\Events\PresenceUpdated;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

afterEach(function () {
    Carbon::setTestNow();
});

describe('presence', function () {
    it('updates last active on heartbeat and broadcasts to friends only', function () {
        Event::fake([PresenceUpdated::class]);
        Carbon::setTestNow('2026-11-19 10:30:00');

        $alice = makeUser('alice');
        $bob = makeUser('bob');
        $stranger = makeUser('stranger');
        makeFriends($alice, $bob);

        actingAsUser($alice)->postJson('/api/presence/heartbeat')
            ->assertOk()
            ->assertJsonPath('ok', true);

        expect($alice->fresh()->last_active_at->toDateTimeString())->toBe('2026-11-19 10:30:00');

        Event::assertDispatched(PresenceUpdated::class, function (PresenceUpdated $event) use ($alice, $bob, $stranger) {
            $channels = array_map(fn ($channel) => $channel->name, $event->broadcastOn());

            return $event->user->id === $alice->id
                && $event->online === true
                && in_array("private-user.{$bob->id}", $channels, true)
                && ! in_array("private-user.{$stranger->id}", $channels, true);
        });
    });

    it('hides last active from friends when the setting is off', function () {
        $alice = makeUser('alice', [], ['show_active_status' => false]);
        $bob = makeUser('bob');
        makeFriends($alice, $bob);

        actingAsUser($alice)->postJson('/api/presence/heartbeat')->assertOk();

        expect($alice->fresh()->last_active_at)->not->toBeNull();

        $presence = collect(actingAsUser($bob)->getJson('/api/presence')->assertOk()->json())
            ->firstWhere('id', $alice->id);

        expect($presence['show_active_status'])->toBeFalse()
            ->and($presence['last_active_at'])->toBeNull();
    });

    it('shows the timestamp to friends when the setting is on', function () {
        $alice = makeUser('alice');
        $bob = makeUser('bob');
        makeFriends($alice, $bob);

        actingAsUser($alice)->postJson('/api/presence/heartbeat')->assertOk();

        $presence = collect(actingAsUser($bob)->getJson('/api/presence')->json())
            ->firstWhere('id', $alice->id);

        expect($presence['show_active_status'])->toBeTrue()
            ->and($presence['last_active_at'])->not->toBeNull();
    });
});

describe('settings', function () {
    it('persists message sounds per user', function () {
        $alice = makeUser('alice');
        $bob = makeUser('bob');

        actingAsUser($alice)->postJson('/api/settings/notifications', [
            'message_sounds' => false,
            'message_sounds_volume' => 35,
        ])->assertOk()
            ->assertJsonPath('message_sounds', false)
            ->assertJsonPath('message_sounds_volume', 35);

        $this->assertDatabaseHas('user_profiles', [
            'user_id' => $alice->id,
            'message_sounds' => 0,
            'message_sounds_volume' => 35,
        ]);
        $this->assertDatabaseHas('user_profiles', [
            'user_id' => $bob->id,
            'message_sounds' => 1,
        ]);

        expect($alice->fresh()->messageSoundsEnabled())->toBeFalse()
            ->and($bob->fresh()->messageSoundsEnabled())->toBeTrue();
    });

    it('persists the theme preference', function () {
        $alice = makeUser('alice');

        actingAsUser($alice)->postJson('/api/settings/appearance', [
            'theme_preference' => 'dark',
        ])->assertOk()->assertJsonPath('theme_preference', 'dark');

        $this->assertDatabaseHas('user_profiles', [
            'user_id' => $alice->id,
            'theme_preference' => 'dark',
        ]);
        expect($alice->fresh()->themePreference())->toBe('dark');

        actingAsUser($alice)->postJson('/api/settings/appearance', [
            'theme_preference' => 'purple',
        ])->assertStatus(422);
    });
});

describe('privacy enforcement', function () {
    it('honours who can send message requests', function () {
        $alice = makeUser('alice');
        $nobody = makeUser('nobody', [], ['message_request_policy' => 'nobody']);

        actingAsUser($alice)->postJson('/api/message-requests', [
            'user_id' => $nobody->id,
            'body' => 'Hello?',
        ])->assertForbidden();

        // friends_of_friends: only reachable through a mutual friend.
        $bob = makeUser('bob');
        $friendOfFriend = makeUser('fof', [], ['message_request_policy' => 'friends_of_friends']);
        $stranger = makeUser('stranger', [], ['message_request_policy' => 'friends_of_friends']);

        makeFriends($alice, $bob);
        makeFriends($bob, $friendOfFriend);

        actingAsUser($alice)->postJson('/api/message-requests', [
            'user_id' => $stranger->id,
            'body' => 'Hello stranger?',
        ])->assertForbidden();

        actingAsUser($alice)->postJson('/api/message-requests', [
            'user_id' => $friendOfFriend->id,
            'body' => 'We have a mutual friend.',
        ])->assertCreated();
    });

    it('honours notes visibility for friends', function () {
        $alice = makeUser('alice', [], ['notes_visibility' => 'nobody']);
        $bob = makeUser('bob');
        makeFriends($alice, $bob);

        $note = actingAsUser($alice)->postJson('/api/notes', ['content' => 'Private thought'])
            ->assertCreated()->json();

        expect(actingAsUser($bob)->getJson('/api/notes')->json('data'))->toBe([]);
        actingAsUser($bob)->getJson("/api/notes/{$note['id']}")->assertForbidden();
    });

    it('sends no seen events when the reader disabled read receipts', function () {
        Event::fake([MessageSeen::class]);

        $alice = makeUser('alice');
        $bob = makeUser('bob', [], ['read_receipts' => false]);
        makeFriends($alice, $bob);
        $conversation = createConversation($alice, $bob);

        $messageId = actingAsUser($alice)->postJson(
            "/api/conversations/{$conversation->id}/messages",
            ['body' => 'No tick please'],
        )->assertCreated()->json('id');

        actingAsUser($bob)->getJson("/api/conversations/{$conversation->id}")->assertOk();

        // The receipt is still stored server-side; only the sender cannot see it.
        $this->assertDatabaseHas('message_reads', [
            'message_id' => $messageId,
            'user_id' => $bob->id,
        ]);
        Event::assertNotDispatched(MessageSeen::class);

        $senderView = collect(
            actingAsUser($alice)->getJson("/api/conversations/{$conversation->id}")->json('data'),
        )->firstWhere('id', $messageId);

        expect($senderView['status'])->toBe('sent')
            ->and($senderView['seen_by'])->toBe([]);
    });
});
