<?php

use App\Models\Call;
use App\Models\Conversation;
use App\Models\Group;
use Illuminate\Support\Facades\Broadcast;

describe('private user channels', function () {
    it('only lets a user join their own channel', function () {
        $alice = makeUser('alice');
        $bob = makeUser('bob');

        expect(channelAllows('user.{id}', $alice, (string) $alice->id))->toBeTrue()
            ->and(channelAllows('user.{id}', $alice, (string) $bob->id))->toBeFalse()
            ->and(channelAllows('users.{id}', $alice, (string) $alice->id))->toBeTrue()
            ->and(channelAllows('users.{id}', $alice, (string) $bob->id))->toBeFalse();
    });

    it('returns the presence payload on the online channel', function () {
        $alice = makeUser('alice');

        $channels = Broadcast::driver()->getChannels();
        $member = $channels['online']($alice);

        expect($member['id'])->toBe($alice->id)
            ->and($member['username'])->toBe('alice')
            ->and($member)->toHaveKey('show_active_status');
    });
});

describe('conversation channels', function () {
    it('lets participants join and keeps outsiders out', function () {
        $alice = makeUser('alice');
        $bob = makeUser('bob');
        $eve = makeUser('eve');
        $conversation = createConversation($alice, $bob);

        expect(channelAllows('conversation.{id}', $alice, (string) $conversation->id))->toBeTrue()
            ->and(channelAllows('conversation.{id}', $bob, (string) $conversation->id))->toBeTrue()
            ->and(channelAllows('conversation.{id}', $eve, (string) $conversation->id))->toBeFalse();
    });

    it('requires live group membership for group conversations', function () {
        $creator = makeUser('creator');
        $member = makeUser('member');
        $outsider = makeUser('outsider');

        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([
            $creator->id => ['role' => 'admin'],
            $member->id => ['role' => 'member'],
        ]);

        $conversation = Conversation::factory()->create([
            'type' => 'group',
            'group_id' => $group->id,
            'direct_key' => null,
        ]);

        expect(channelAllows('conversation.{id}', $creator, (string) $conversation->id))->toBeTrue()
            ->and(channelAllows('conversation.{id}', $member, (string) $conversation->id))->toBeTrue()
            ->and(channelAllows('conversation.{id}', $outsider, (string) $conversation->id))->toBeFalse();

        $group->members()->detach($member->id);
        expect(channelAllows('conversation.{id}', $member, (string) $conversation->id))->toBeFalse();
    });

    it('denies unknown conversations', function () {
        $alice = makeUser('alice');

        expect(channelAllows('conversation.{id}', $alice, '99999'))->toBeFalse();
    });
});

describe('call channels', function () {
    it('only lets the two participants join a call channel', function () {
        $alice = makeUser('alice');
        $bob = makeUser('bob');
        $eve = makeUser('eve');

        $call = Call::factory()->create([
            'caller_id' => $alice->id,
            'callee_id' => $bob->id,
        ]);

        expect(channelAllows('call.{callId}', $alice, (string) $call->id))->toBeTrue()
            ->and(channelAllows('call.{callId}', $bob, (string) $call->id))->toBeTrue()
            ->and(channelAllows('call.{callId}', $eve, (string) $call->id))->toBeFalse()
            ->and(channelAllows('call.{callId}', $alice, '99999'))->toBeFalse();
    });
});
