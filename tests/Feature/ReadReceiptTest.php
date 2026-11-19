<?php

namespace Tests\Feature;

use App\Events\MessageSeen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

class ReadReceiptTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_seen_only_when_recipient_opens_history_and_is_idempotent(): void
    {
        Event::fake([MessageSeen::class]);

        $alice = $this->userWithProfile('alice');
        $bob = $this->userWithProfile('bob');
        $this->befriend($alice, $bob);

        $conversationId = $this->actingAs($alice)
            ->postJson("/api/conversations/direct/{$bob->id}")->json('id');

        $sent = $this->actingAs($alice)
            ->postJson("/api/conversations/{$conversationId}/messages", ['body' => 'Hello'])
            ->assertCreated()->json();

        $messageId = $sent['id'];
        $this->assertSame('sent', $sent['status']);
        $this->assertSame([], $sent['seen_by']);
        $this->assertDatabaseMissing('message_reads', [
            'message_id' => $messageId,
            'user_id' => $bob->id,
        ]);
        Event::assertNotDispatched(MessageSeen::class);

        $history = $this->actingAs($bob)->getJson("/api/conversations/{$conversationId}")->assertOk()->json();
        $received = collect($history['data'])->firstWhere('id', $messageId);

        // The response itself clears the badge even if a websocket event is missed.
        $this->assertSame(0, $history['unread']);
        $this->assertSame(0, $history['counts'][$conversationId] ?? 0);
        $this->assertSame('seen', $received['status']);
        $this->assertSame($bob->id, $received['seen_by'][0]['user_id']);
        $this->assertDatabaseHas('message_reads', [
            'message_id' => $messageId,
            'user_id' => $bob->id,
        ]);
        Event::assertDispatchedTimes(MessageSeen::class, 1);
        Event::assertDispatched(
            MessageSeen::class,
            fn (MessageSeen $event) => $event->read->message_id === $messageId
                && $event->read->user_id === $bob->id
                && ($event->counts[$conversationId] ?? 0) === 0
                && $event->broadcastOn()->name === "private-conversation.{$conversationId}",
        );

        // Re-reading the thread must not produce duplicate receipts or events.
        $this->actingAs($bob)->getJson("/api/conversations/{$conversationId}")->assertOk();
        $this->assertDatabaseCount('message_reads', 2); // sender + recipient
        Event::assertDispatchedTimes(MessageSeen::class, 1);
    }

    public function test_group_receipts_report_every_reader_and_exclude_outsiders(): void
    {
        $alice = $this->userWithProfile('alice');
        $bob = $this->userWithProfile('bob');
        $charlie = $this->userWithProfile('charlie');
        $eve = $this->userWithProfile('eve');

        $group = $this->actingAs($alice)->postJson('/api/groups', [
            'name' => 'Trip',
            'member_ids' => [$bob->id, $charlie->id],
        ])->assertCreated()->json();
        $conversationId = $group['conversation_id'];

        $sent = $this->actingAs($alice)
            ->postJson("/api/conversations/{$conversationId}/messages", ['body' => 'Meet at 9'])
            ->assertCreated()->json();

        $this->actingAs($eve)->getJson("/api/conversations/{$conversationId}")->assertForbidden();

        $this->actingAs($bob)->getJson("/api/conversations/{$conversationId}")->assertOk();
        $afterBob = collect($this->actingAs($alice)->getJson("/api/conversations/{$conversationId}")->json()['data'])
            ->firstWhere('id', $sent['id']);
        $this->assertSame([$bob->id], array_column($afterBob['seen_by'], 'user_id'));

        $this->actingAs($charlie)->getJson("/api/conversations/{$conversationId}")->assertOk();
        $afterCharlie = collect($this->actingAs($alice)->getJson("/api/conversations/{$conversationId}")->json()['data'])
            ->firstWhere('id', $sent['id']);
        $this->assertEqualsCanonicalizing(
            [$bob->id, $charlie->id],
            array_column($afterCharlie['seen_by'], 'user_id'),
        );

        // A removed member can no longer read or generate receipts.
        $this->actingAs($alice)->deleteJson("/api/groups/{$group['id']}/members/{$bob->id}")->assertOk();
        $this->actingAs($bob)->getJson("/api/conversations/{$conversationId}")->assertForbidden();
    }

    public function test_receipts_survive_cursor_pagination(): void
    {
        $alice = $this->userWithProfile('alice');
        $bob = $this->userWithProfile('bob');
        $this->befriend($alice, $bob);

        $conversationId = $this->actingAs($alice)
            ->postJson("/api/conversations/direct/{$bob->id}")->json('id');

        for ($i = 1; $i <= 35; $i++) {
            $this->actingAs($alice)->postJson(
                "/api/conversations/{$conversationId}/messages",
                ['body' => "Message {$i}"],
            )->assertCreated();
        }

        $this->actingAs($bob)->getJson("/api/conversations/{$conversationId}")->assertOk();

        $first = $this->actingAs($bob)->getJson("/api/conversations/{$conversationId}")->assertOk()->json();
        $this->assertCount(30, $first['data']);
        $this->assertNotEmpty($first['next_cursor']);
        $this->assertTrue(collect($first['data'])->every(fn ($m) => $m['status'] === 'seen'));

        $second = $this->actingAs($bob)
            ->getJson("/api/conversations/{$conversationId}?cursor=".urlencode($first['next_cursor']))
            ->assertOk()->json();

        $this->assertCount(5, $second['data']);
        $this->assertTrue(collect($second['data'])->every(
            fn ($m) => $m['status'] === 'seen' && count($m['seen_by']) === 1,
        ));
    }
}
