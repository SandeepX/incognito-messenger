<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ChecksChannels;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

class DirectMessagingTest extends TestCase
{
    use ChecksChannels, CreatesUsers, RefreshDatabase;

    private function startConversation($alice, $bob): int
    {
        return $this->actingAs($alice)
            ->postJson("/api/conversations/direct/{$bob->id}")
            ->assertOk()
            ->json('id');
    }

    public function test_direct_conversation_is_unique_and_only_visible_to_participants(): void
    {
        $alice = $this->userWithProfile('alice');
        $bob = $this->userWithProfile('bob');
        $eve = $this->userWithProfile('eve');

        $id = $this->startConversation($alice, $bob);

        // Repeating the request must reuse the same thread.
        $this->actingAs($bob)
            ->postJson("/api/conversations/direct/{$alice->id}")
            ->assertOk()
            ->assertJsonPath('id', $id);

        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('conversation_participants', 2);

        // Outsiders cannot read history or write to it.
        $this->actingAs($eve)->getJson("/api/conversations/{$id}")->assertForbidden();
        $this->actingAs($eve)
            ->postJson("/api/conversations/{$id}/messages", ['body' => 'sneaky'])
            ->assertForbidden();

        // Outsiders cannot subscribe to the private conversation channel.
        $this->assertFalse($this->channelAllows('conversation.{id}', $eve, $id));
        $this->assertTrue($this->channelAllows('conversation.{id}', $alice, $id));
        $this->assertTrue($this->channelAllows('conversation.{id}', $bob, $id));

        // Per-user channels only accept their owner.
        $this->assertTrue($this->channelAllows('user.{id}', $alice, $alice->id));
        $this->assertFalse($this->channelAllows('user.{id}', $alice, $bob->id));

        $this->actingAs($alice)->getJson("/api/conversations/{$id}")->assertOk();
    }

    public function test_messages_are_persisted_with_the_authenticated_sender(): void
    {
        $alice = $this->userWithProfile('alice');
        $bob = $this->userWithProfile('bob');
        $id = $this->startConversation($alice, $bob);

        $this->actingAs($alice)
            ->postJson("/api/conversations/{$id}/messages", ['body' => 'Hello Bob'])
            ->assertCreated()
            ->assertJsonPath('body', 'Hello Bob')
            ->assertJsonPath('sender_id', $alice->id)
            ->assertJsonPath('sender_name', $alice->name);

        // The sender cannot spoof someone else's identity.
        $this->actingAs($alice)
            ->postJson("/api/conversations/{$id}/messages", ['body' => 'fake', 'sender_id' => $bob->id])
            ->assertCreated()
            ->assertJsonPath('sender_id', $alice->id);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $id,
            'sender_id' => $alice->id,
            'body' => 'Hello Bob',
        ]);

        $history = $this->actingAs($bob)->getJson("/api/conversations/{$id}")->assertOk()->json();
        $this->assertCount(2, $history['data']);
        $this->assertSame('Hello Bob', $history['data'][1]['body']);
        $this->assertSame($alice->name, $history['data'][1]['sender_name']);
        $this->assertArrayHasKey('sender_avatar', $history['data'][1]);
        $this->assertArrayHasKey('created_at', $history['data'][1]);
    }

    public function test_unread_counts_increment_and_clear_on_read(): void
    {
        $alice = $this->userWithProfile('alice');
        $bob = $this->userWithProfile('bob');
        $id = $this->startConversation($alice, $bob);

        $this->actingAs($alice)->postJson("/api/conversations/{$id}/messages", ['body' => 'one'])->assertCreated();
        $this->actingAs($alice)->postJson("/api/conversations/{$id}/messages", ['body' => 'two'])->assertCreated();

        $list = $this->actingAs($bob)->getJson('/api/conversations')->assertOk()->json();
        $this->assertSame(2, collect($list)->firstWhere('id', $id)['unread']);

        // The sender never gets an unread badge for their own messages.
        $ownList = $this->actingAs($alice)->getJson('/api/conversations')->assertOk()->json();
        $this->assertSame(0, collect($ownList)->firstWhere('id', $id)['unread']);

        // Opening the thread marks everything as read.
        $this->actingAs($bob)->getJson("/api/conversations/{$id}")->assertOk();

        $list = $this->actingAs($bob)->getJson('/api/conversations')->assertOk()->json();
        $this->assertSame(0, collect($list)->firstWhere('id', $id)['unread']);
        $this->assertDatabaseCount('message_reads', 4); // 2 by Alice (own) + 2 by Bob (on read)
    }

    public function test_conversation_list_only_contains_own_threads_and_sorts_by_latest(): void
    {
        $alice = $this->userWithProfile('alice');
        $bob = $this->userWithProfile('bob');
        $eve = $this->userWithProfile('eve');

        $ab = $this->startConversation($alice, $bob);
        $be = $this->startConversation($bob, $eve);

        $this->actingAs($alice)->postJson("/api/conversations/{$ab}/messages", ['body' => 'first'])->assertCreated();
        $this->actingAs($eve)->postJson("/api/conversations/{$be}/messages", ['body' => 'second'])->assertCreated();

        $aliceList = $this->actingAs($alice)->getJson('/api/conversations')->json();
        $this->assertCount(1, $aliceList);
        $this->assertSame($ab, $aliceList[0]['id']);
        $this->assertSame($bob->name, $aliceList[0]['name']);
        $this->assertSame('@bob', $aliceList[0]['subtitle']);
        $this->assertSame('first', $aliceList[0]['last_message']['body']);

        $eveList = $this->actingAs($eve)->getJson('/api/conversations')->json();
        $this->assertCount(1, $eveList);
        $this->assertSame($be, $eveList[0]['id']);
    }

    public function test_history_is_cursor_paginated_for_older_messages(): void
    {
        $alice = $this->userWithProfile('alice');
        $bob = $this->userWithProfile('bob');
        $id = $this->startConversation($alice, $bob);

        for ($i = 1; $i <= 35; $i++) {
            $this->actingAs($alice)
                ->postJson("/api/conversations/{$id}/messages", ['body' => "message {$i}"])
                ->assertCreated();
        }

        $first = $this->actingAs($bob)->getJson("/api/conversations/{$id}")->assertOk()->json();

        $this->assertCount(30, $first['data']);
        $this->assertSame('message 35', $first['data'][0]['body']);
        $this->assertNotNull($first['next_cursor']);

        $second = $this->actingAs($bob)
            ->getJson("/api/conversations/{$id}?cursor=".urlencode($first['next_cursor']))
            ->assertOk()
            ->json();

        $this->assertCount(5, $second['data']);
        $this->assertSame('message 5', $second['data'][0]['body']);
        $this->assertSame('message 1', $second['data'][4]['body']);
        $this->assertNull($second['next_cursor']);
    }

    public function test_user_cannot_open_a_conversation_with_themselves(): void
    {
        $alice = $this->userWithProfile('alice');

        $this->actingAs($alice)
            ->postJson("/api/conversations/direct/{$alice->id}")
            ->assertStatus(422);

        $this->assertDatabaseCount('conversations', 0);
    }
}
