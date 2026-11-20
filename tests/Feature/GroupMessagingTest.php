<?php

use App\Models\User;

function groupTestHelperCreateGroup(User $creator, array $members = []): array
{
    return test()->actingAs($creator)->postJson('/api/groups', [
        'name' => 'Weekend Trip',
        'member_ids' => collect($members)->map(fn (User $u) => $u->id)->all(),
    ])->assertCreated()->json();
}

it('creates a group with matching members, conversation and channel authorization', function () {
    $admin = $this->userWithProfile('admin');
    $member = $this->userWithProfile('member');
    $outsider = $this->userWithProfile('outsider');

    $group = groupTestHelperCreateGroup($admin, [$member]);
    $groupId = $group['id'];
    $conversationId = $group['conversation_id'];

    $this->assertDatabaseHas('groups', ['id' => $groupId, 'creator_id' => $admin->id]);
    $this->assertDatabaseHas('group_members', ['group_id' => $groupId, 'user_id' => $admin->id, 'role' => 'admin']);
    $this->assertDatabaseHas('group_members', ['group_id' => $groupId, 'user_id' => $member->id, 'role' => 'member']);
    $this->assertDatabaseCount('conversation_participants', 2);

    $this->actingAs($member)->getJson("/api/groups/{$groupId}")
        ->assertOk()
        ->assertJsonPath('name', 'Weekend Trip')
        ->assertJsonPath('is_admin', false);

    $this->actingAs($admin)->getJson("/api/groups/{$groupId}")
        ->assertJsonPath('is_admin', true);

    $this->actingAs($outsider)->getJson("/api/groups/{$groupId}")->assertForbidden();
    $this->actingAs($outsider)->getJson("/api/conversations/{$conversationId}")->assertForbidden();
    $this->assertFalse($this->channelAllows('conversation.{id}', $outsider, $conversationId));
    $this->assertTrue($this->channelAllows('conversation.{id}', $member, $conversationId));
    $this->assertTrue($this->channelAllows('conversation.{id}', $admin, $conversationId));
});

it('allows only admins to add members', function () {
    $admin = $this->userWithProfile('admin');
    $member = $this->userWithProfile('member');
    $newcomer = $this->userWithProfile('newcomer');

    $group = groupTestHelperCreateGroup($admin, [$member]);

    $this->actingAs($member)->postJson("/api/groups/{$group['id']}/members", [
        'user_id' => $newcomer->id,
    ])->assertForbidden();

    $this->actingAs($admin)->postJson("/api/groups/{$group['id']}/members", [
        'user_id' => $newcomer->id,
    ])->assertOk();

    $this->assertDatabaseHas('group_members', [
        'group_id' => $group['id'], 'user_id' => $newcomer->id, 'role' => 'member',
    ]);
    $this->assertDatabaseHas('conversation_participants', [
        'conversation_id' => $group['conversation_id'], 'user_id' => $newcomer->id,
    ]);
});

it('immediately revokes all future access for a removed member', function () {
    $admin = $this->userWithProfile('admin');
    $member = $this->userWithProfile('member');

    $group = groupTestHelperCreateGroup($admin, [$member]);
    $groupId = $group['id'];
    $conversationId = $group['conversation_id'];

    $this->actingAs($member)->postJson("/api/conversations/{$conversationId}/messages", [
        'body' => 'before removal',
    ])->assertCreated();

    $this->actingAs($member)
        ->deleteJson("/api/groups/{$groupId}/members/{$admin->id}")
        ->assertForbidden();

    $this->actingAs($admin)
        ->deleteJson("/api/groups/{$groupId}/members/{$admin->id}")
        ->assertStatus(422);

    $this->actingAs($admin)
        ->deleteJson("/api/groups/{$groupId}/members/{$member->id}")
        ->assertOk();

    $this->assertDatabaseMissing('group_members', [
        'group_id' => $groupId, 'user_id' => $member->id,
    ]);
    $this->assertDatabaseMissing('conversation_participants', [
        'conversation_id' => $conversationId, 'user_id' => $member->id,
    ]);

    $this->actingAs($member)->getJson("/api/groups/{$groupId}")->assertForbidden();
    $this->actingAs($member)->getJson("/api/conversations/{$conversationId}")->assertForbidden();
    $this->actingAs($member)->postJson("/api/conversations/{$conversationId}/messages", [
        'body' => 'after removal',
    ])->assertForbidden();
    $this->assertFalse($this->channelAllows('conversation.{id}', $member, $conversationId));

    $this->actingAs($member)->getJson('/api/conversations')->assertOk()->assertJsonCount(0);

    $this->actingAs($admin)->postJson("/api/conversations/{$conversationId}/messages", [
        'body' => 'still here',
    ])->assertCreated();
});

it('lets members leave but requires the creator to delete the group', function () {
    $admin = $this->userWithProfile('admin');
    $member = $this->userWithProfile('member');

    $group = groupTestHelperCreateGroup($admin, [$member]);

    $this->actingAs($admin)->postJson("/api/groups/{$group['id']}/leave")->assertForbidden();

    $this->actingAs($member)->postJson("/api/groups/{$group['id']}/leave")->assertOk();

    $this->assertDatabaseMissing('group_members', [
        'group_id' => $group['id'], 'user_id' => $member->id,
    ]);
    $this->actingAs($member)->getJson("/api/conversations/{$group['conversation_id']}")->assertForbidden();
});

it('cascades everything when a group is deleted', function () {
    $admin = $this->userWithProfile('admin');
    $member = $this->userWithProfile('member');

    $group = groupTestHelperCreateGroup($admin, [$member]);
    $this->actingAs($admin)->postJson("/api/conversations/{$group['conversation_id']}/messages", [
        'body' => 'bye',
    ])->assertCreated();

    $this->actingAs($member)->deleteJson("/api/groups/{$group['id']}")->assertForbidden();

    $this->actingAs($admin)->deleteJson("/api/groups/{$group['id']}")->assertOk();

    $this->assertDatabaseMissing('groups', ['id' => $group['id']]);
    $this->assertDatabaseMissing('conversations', ['id' => $group['conversation_id']]);
    $this->assertDatabaseCount('messages', 0);
    $this->assertDatabaseCount('group_members', 0);
    $this->assertDatabaseCount('conversation_participants', 0);
});

it('validates group creation input', function () {
    $admin = $this->userWithProfile('admin');

    $this->actingAs($admin)->postJson('/api/groups', [
        'name' => '',
        'member_ids' => [],
    ])->assertStatus(422)->assertJsonValidationErrors(['name', 'member_ids']);

    $this->actingAs($admin)->postJson('/api/groups', [
        'name' => 'Valid name',
        'member_ids' => [999999],
    ])->assertStatus(422)->assertJsonValidationErrors(['member_ids.0']);
});
