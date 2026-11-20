<?php

function dmTestHelperStartConversation($alice, $bob): int
{
    test()->befriend($alice, $bob);

    return test()->actingAs($alice)
        ->postJson("/api/conversations/direct/{$bob->id}")
        ->assertOk()
        ->json('id');
}

it('creates a unique direct conversation that is only visible to participants', function () {
    $alice = $this->userWithProfile('alice');
    $bob = $this->userWithProfile('bob');
    $eve = $this->userWithProfile('eve');

    $id = dmTestHelperStartConversation($alice, $bob);

    $this->actingAs($bob)
        ->postJson("/api/conversations/direct/{$alice->id}")
        ->assertOk()
        ->assertJsonPath('id', $id);

    $this->assertDatabaseCount('conversations', 1);
    $this->assertDatabaseCount('conversation_participants', 2);

    $this->actingAs($eve)->getJson("/api/conversations/{$id}")->assertForbidden();
    $this->actingAs($eve)
        ->postJson("/api/conversations/{$id}/messages", ['body' => 'sneaky'])
        ->assertForbidden();

    $this->assertFalse($this->channelAllows('conversation.{id}', $eve, $id));
    $this->assertTrue($this->channelAllows('conversation.{id}', $alice, $id));
    $this->assertTrue($this->channelAllows('conversation.{id}', $bob, $id));

    $this->assertTrue($this->channelAllows('user.{id}', $alice, $alice->id));
    $this->assertFalse($this->channelAllows('user.{id}', $alice, $bob->id));

    $this->actingAs($alice)->getJson("/api/conversations/{$id}")->assertOk();
});

it('persists messages with the authenticated sender', function () {
    $alice = $this->userWithProfile('alice');
    $bob = $this->userWithProfile('bob');
    $id = dmTestHelperStartConversation($alice, $bob);

    $this->actingAs($alice)
        ->postJson("/api/conversations/{$id}/messages", ['body' => 'Hello Bob'])
        ->assertCreated()
        ->assertJsonPath('body', 'Hello Bob')
        ->assertJsonPath('sender_id', $alice->id)
        ->assertJsonPath('sender_name', $alice->name);

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
});

it('increments unread counts and clears them on read', function () {
    $alice = $this->userWithProfile('alice');
    $bob = $this->userWithProfile('bob');
    $id = dmTestHelperStartConversation($alice, $bob);

    $this->actingAs($alice)->postJson("/api/conversations/{$id}/messages", ['body' => 'one'])->assertCreated();
    $this->actingAs($alice)->postJson("/api/conversations/{$id}/messages", ['body' => 'two'])->assertCreated();

    $list = $this->actingAs($bob)->getJson('/api/conversations')->assertOk()->json();
    $this->assertSame(2, collect($list)->firstWhere('id', $id)['unread']);

    $ownList = $this->actingAs($alice)->getJson('/api/conversations')->assertOk()->json();
    $this->assertSame(0, collect($ownList)->firstWhere('id', $id)['unread']);

    $this->actingAs($bob)->getJson("/api/conversations/{$id}")->assertOk();

    $list = $this->actingAs($bob)->getJson('/api/conversations')->assertOk()->json();
    $this->assertSame(0, collect($list)->firstWhere('id', $id)['unread']);
    $this->assertDatabaseCount('message_reads', 4);
});

it('lists only own threads and sorts them by the latest message', function () {
    $alice = $this->userWithProfile('alice');
    $bob = $this->userWithProfile('bob');
    $eve = $this->userWithProfile('eve');

    $ab = dmTestHelperStartConversation($alice, $bob);
    $be = dmTestHelperStartConversation($bob, $eve);

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
});

it('cursor-paginates history for older messages', function () {
    $alice = $this->userWithProfile('alice');
    $bob = $this->userWithProfile('bob');
    $id = dmTestHelperStartConversation($alice, $bob);

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
});

it('does not let a user open a conversation with themselves', function () {
    $alice = $this->userWithProfile('alice');

    $this->actingAs($alice)
        ->postJson("/api/conversations/direct/{$alice->id}")
        ->assertStatus(422);

    $this->assertDatabaseCount('conversations', 0);
});
