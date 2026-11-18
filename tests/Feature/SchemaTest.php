<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_messenger_tables_exist_with_expected_columns(): void
    {
        $expected = [
            'users' => ['name', 'email', 'password', 'last_active_at'],
            'user_profiles' => ['user_id', 'username', 'avatar_path', 'status', 'bio', 'show_active_status', 'message_sounds'],
            'groups' => ['name', 'avatar_path', 'description', 'creator_id'],
            'conversations' => ['type', 'group_id', 'direct_key'],
            'conversation_participants' => ['conversation_id', 'user_id', 'last_read_at'],
            'group_members' => ['group_id', 'user_id', 'role'],
            'messages' => ['conversation_id', 'sender_id', 'body', 'is_unsent', 'unsent_at', 'unsent_by', 'deleted_for'],
            'message_reads' => ['message_id', 'user_id', 'read_at'],
            'friendships' => ['user_id', 'friend_id', 'pair_key'],
            'friend_requests' => ['sender_id', 'recipient_id', 'pair_key', 'status'],
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
    }

    public function test_unique_indexes_prevent_duplicate_usernames_participants_and_reads(): void
    {
        $this->assertTrue($this->hasUniqueIndex('user_profiles', 'user_profiles_username_unique'));
        $this->assertTrue($this->hasUniqueIndex('conversations', 'conversations_direct_key_unique'));
        $this->assertTrue($this->hasUniqueIndex('conversations', 'conversations_group_id_unique'));
        $this->assertTrue($this->hasUniqueIndex('conversation_participants', 'conversation_participants_conversation_id_user_id_unique'));
        $this->assertTrue($this->hasUniqueIndex('group_members', 'group_members_group_id_user_id_unique'));
        $this->assertTrue($this->hasUniqueIndex('message_reads', 'message_reads_message_id_user_id_unique'));
        $this->assertTrue($this->hasUniqueIndex('friendships', 'friendships_pair_key_unique'));
        $this->assertTrue($this->hasUniqueIndex('friend_requests', 'friend_requests_pair_key_unique'));
        $this->assertTrue($this->hasUniqueIndex('message_requests', 'message_requests_pair_key_unique'));
        $this->assertTrue($this->hasUniqueIndex('message_requests', 'message_requests_conversation_id_unique'));
    }

    private function hasUniqueIndex(string $table, string $index): bool
    {
        return collect(\Illuminate\Support\Facades\DB::select("PRAGMA index_list('{$table}')"))
            ->firstWhere('name', $index) !== null;
    }
}
