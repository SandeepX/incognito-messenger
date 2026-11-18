<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ChecksChannels;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

class GroupMessagingTest extends TestCase
{
    use ChecksChannels, CreatesUsers, RefreshDatabase;

    private function createGroup(User $creator, array $members = []): array
    {
        return $this->actingAs($creator)->postJson('/api/groups', [
            'name' => 'Weekend Trip',
            'member_ids' => collect($members)->map(fn (User $u) => $u->id)->all(),
        ])->assertCreated()->json();
    }

    public function test_group_creation_matches_members_conversation_and_channel_auth(): void
    {
        $admin = $this->userWithProfile('admin');
        $member = $this->userWithProfile('member');
        $outsider = $this->userWithProfile('outsider');

        $group = $this->createGroup($admin, [$member]);
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

        // Outsiders are denied by the API and by broadcasting authorization.
        $this->actingAs($outsider)->getJson("/api/groups/{$groupId}")->assertForbidden();
        $this->actingAs($outsider)->getJson("/api/conversations/{$conversationId}")->assertForbidden();
        $this->assertFalse($this->channelAllows('conversation.{id}', $outsider, $conversationId));
        $this->assertTrue($this->channelAllows('conversation.{id}', $member, $conversationId));
        $this->assertTrue($this->channelAllows('conversation.{id}', $admin, $conversationId));
    }

    public function test_only_admins_can_add_members(): void
    {
        $admin = $this->userWithProfile('admin');
        $member = $this->userWithProfile('member');
        $newcomer = $this->userWithProfile('newcomer');

        $group = $this->createGroup($admin, [$member]);

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
    }

    public function test_removed_member_immediately_loses_all_future_access(): void
    {
        $admin = $this->userWithProfile('admin');
        $member = $this->userWithProfile('member');

        $group = $this->createGroup($admin, [$member]);
        $groupId = $group['id'];
        $conversationId = $group['conversation_id'];

        $this->actingAs($member)->postJson("/api/conversations/{$conversationId}/messages", [
            'body' => 'before removal',
        ])->assertCreated();

        // A non-admin cannot remove anyone.
        $this->actingAs($member)
            ->deleteJson("/api/groups/{$groupId}/members/{$admin->id}")
            ->assertForbidden();

        // The creator cannot be removed, even by another admin.
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

        // Every access path is closed from this point on.
        $this->actingAs($member)->getJson("/api/groups/{$groupId}")->assertForbidden();
        $this->actingAs($member)->getJson("/api/conversations/{$conversationId}")->assertForbidden();
        $this->actingAs($member)->postJson("/api/conversations/{$conversationId}/messages", [
            'body' => 'after removal',
        ])->assertForbidden();
        $this->assertFalse($this->channelAllows('conversation.{id}', $member, $conversationId));

        // Past messages are not visible through the conversation list either.
        $this->actingAs($member)->getJson('/api/conversations')->assertOk()->assertJsonCount(0);

        // Remaining members can still chat.
        $this->actingAs($admin)->postJson("/api/conversations/{$conversationId}/messages", [
            'body' => 'still here',
        ])->assertCreated();
    }

    public function test_members_can_leave_but_creator_must_delete(): void
    {
        $admin = $this->userWithProfile('admin');
        $member = $this->userWithProfile('member');

        $group = $this->createGroup($admin, [$member]);

        $this->actingAs($admin)->postJson("/api/groups/{$group['id']}/leave")->assertForbidden();

        $this->actingAs($member)->postJson("/api/groups/{$group['id']}/leave")->assertOk();

        $this->assertDatabaseMissing('group_members', [
            'group_id' => $group['id'], 'user_id' => $member->id,
        ]);
        $this->actingAs($member)->getJson("/api/conversations/{$group['conversation_id']}")->assertForbidden();
    }

    public function test_deleting_a_group_cascades_everything(): void
    {
        $admin = $this->userWithProfile('admin');
        $member = $this->userWithProfile('member');

        $group = $this->createGroup($admin, [$member]);
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
    }

    public function test_group_creation_validates_input(): void
    {
        $admin = $this->userWithProfile('admin');

        $this->actingAs($admin)->postJson('/api/groups', [
            'name' => '',
            'member_ids' => [],
        ])->assertStatus(422)->assertJsonValidationErrors(['name', 'member_ids']);

        $this->actingAs($admin)->postJson('/api/groups', [
            'name' => 'Valid name',
            'member_ids' => [999999],
        ])->assertStatus(422)->assertJsonValidationErrors(['member_ids.0']);
    }
}
