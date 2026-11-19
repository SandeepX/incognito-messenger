<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function schemaTestHasUniqueIndex(string $table, string $index): bool
{
    return collect(DB::select("PRAGMA index_list('{$table}')"))
        ->firstWhere('name', $index) !== null;
}

it('has all messenger tables with the expected columns', function () {
    $expected = [
        'users' => ['name', 'email', 'password', 'last_active_at'],
        'user_profiles' => ['user_id', 'username', 'avatar_path', 'status', 'bio', 'show_active_status', 'message_sounds', 'allow_calls', 'call_ringtone'],
        'groups' => ['name', 'avatar_path', 'description', 'creator_id'],
        'conversations' => ['type', 'group_id', 'direct_key'],
        'conversation_participants' => ['conversation_id', 'user_id', 'last_read_at'],
        'group_members' => ['group_id', 'user_id', 'role'],
        'messages' => ['conversation_id', 'sender_id', 'body', 'type', 'call_id', 'is_unsent', 'unsent_at', 'unsent_by', 'deleted_for'],
        'calls' => ['caller_id', 'callee_id', 'conversation_id', 'status', 'end_reason', 'ended_by', 'ringing_at', 'answered_at', 'ended_at', 'duration_seconds', 'caller_tab_id', 'callee_tab_id'],
        'message_reads' => ['message_id', 'user_id', 'read_at'],
        'friendships' => ['user_id', 'friend_id', 'pair_key'],
        'friend_requests' => ['sender_id', 'recipient_id', 'pair_key', 'status', 'note'],
        'message_requests' => ['sender_id', 'recipient_id', 'conversation_id', 'pair_key', 'status'],
        'message_attachments' => ['message_id', 'storage_path', 'mime_type', 'file_size', 'attachment_type'],
        'notes' => ['user_id', 'content', 'expires_at'],
        'notifications' => ['user_id', 'type', 'data', 'read_at'],
    ];

    foreach ($expected as $table => $columns) {
        $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        foreach ($columns as $column) {
            $this->assertTrue(Schema::hasColumn($table, $column), "Missing column: {$table}.{$column}");
        }
    }
});

it('prevents duplicate usernames, participants, and reads with unique indexes', function () {
    $this->assertTrue(schemaTestHasUniqueIndex('user_profiles', 'user_profiles_username_unique'));
    $this->assertTrue(schemaTestHasUniqueIndex('conversations', 'conversations_direct_key_unique'));
    $this->assertTrue(schemaTestHasUniqueIndex('conversations', 'conversations_group_id_unique'));
    $this->assertTrue(schemaTestHasUniqueIndex('conversation_participants', 'conversation_participants_conversation_id_user_id_unique'));
    $this->assertTrue(schemaTestHasUniqueIndex('group_members', 'group_members_group_id_user_id_unique'));
    $this->assertTrue(schemaTestHasUniqueIndex('message_reads', 'message_reads_message_id_user_id_unique'));
    $this->assertTrue(schemaTestHasUniqueIndex('friendships', 'friendships_pair_key_unique'));
    $this->assertTrue(schemaTestHasUniqueIndex('friend_requests', 'friend_requests_pair_key_unique'));
    $this->assertTrue(schemaTestHasUniqueIndex('message_requests', 'message_requests_pair_key_unique'));
    $this->assertTrue(schemaTestHasUniqueIndex('message_requests', 'message_requests_conversation_id_unique'));
    $this->assertTrue(schemaTestHasUniqueIndex('messages', 'messages_call_id_unique'));
});
