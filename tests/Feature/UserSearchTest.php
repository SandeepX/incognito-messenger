<?php

use App\Models\Conversation;

it('filters every exclusion on the server for find-friends', function () {
    $me = $this->userWithProfile('me');
    $friend = $this->userWithProfile('friend');
    $chatter = $this->userWithProfile('chatter');
    $groupmate = $this->userWithProfile('groupmate');
    $incoming = $this->userWithProfile('incoming');
    $outgoing = $this->userWithProfile('outgoing');
    $available = $this->userWithProfile('available');

    $this->befriend($me, $friend);

    // Seed threads directly: a shared chat excludes a user regardless of
    // whether the friendship was ever accepted.
    $direct = Conversation::create([
        'type' => 'direct',
        'direct_key' => Conversation::directKey($me->id, $chatter->id),
    ]);
    $direct->participants()->attach([$me->id, $chatter->id]);

    $group = Conversation::create(['type' => 'group']);
    $group->participants()->attach([$me->id, $groupmate->id]);

    $this->actingAs($incoming)->postJson('/api/friends', ['user_id' => $me->id])->assertCreated();
    $this->actingAs($me)->postJson('/api/friends', ['user_id' => $outgoing->id])->assertCreated();

    $ids = collect($this->actingAs($me)->getJson('/api/users?scope=find-friends')->json())
        ->pluck('id')->all();

    $this->assertContains($available->id, $ids);
    foreach ([$me, $friend, $chatter, $groupmate, $incoming, $outgoing] as $excluded) {
        $this->assertNotContains($excluded->id, $ids, "{$excluded->username} should be excluded.");
    }
});

it('keeps unscoped user search available for group pickers', function () {
    $me = $this->userWithProfile('me');
    $friend = $this->userWithProfile('friend');
    $chatter = $this->userWithProfile('chatter');

    $this->befriend($me, $friend);
    $direct = Conversation::create([
        'type' => 'direct',
        'direct_key' => Conversation::directKey($me->id, $chatter->id),
    ]);
    $direct->participants()->attach([$me->id, $chatter->id]);

    $ids = collect($this->actingAs($me)->getJson('/api/users')->json())->pluck('id')->all();

    $this->assertNotContains($me->id, $ids);
    $this->assertContains($friend->id, $ids);
    $this->assertContains($chatter->id, $ids);
});

it('searches find-friends by name and username', function () {
    $alice = $this->userWithProfile('alice');
    $bob = $this->userWithProfile('bob');

    $this->actingAs($bob)->getJson('/api/users?scope=find-friends&q=ali')
        ->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $alice->id);

    $this->actingAs($bob)->getJson('/api/users?scope=find-friends&q=alice')
        ->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $alice->id);

    $this->actingAs($bob)->getJson('/api/users?scope=find-friends&q=nobody')
        ->assertOk()->assertJsonCount(0);
});
