<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\MessageRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

class MessageRequestTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_non_friend_can_send_a_request_that_is_hidden_until_accepted(): void
    {
        $alice = $this->userWithProfile('alice');
        $bob = $this->userWithProfile('bob');
        $eve = $this->userWithProfile('eve');

        $response = $this->actingAs($alice)->postJson('/api/message-requests', [
            'user_id' => $bob->id,
            'body' => 'Hi Bob, let us connect.',
        ])->assertCreated();

        $conversationId = $response->json('conversation_id');
        $this->assertDatabaseHas('message_requests', [
            'sender_id' => $alice->id,
            'recipient_id' => $bob->id,
            'status' => 'pending',
        ]);

        // Neither participant sees it in the normal chat list while pending.
        $this->assertCount(0, $this->actingAs($alice)->getJson('/api/conversations')->json());
        $this->assertCount(0, $this->actingAs($bob)->getJson('/api/conversations')->json());

        // Only the two participants may preview it.
        $requestId = $response->json('id');
        $this->actingAs($eve)->getJson("/api/message-requests/{$requestId}")->assertForbidden();
        $preview = $this->actingAs($bob)->getJson("/api/message-requests/{$requestId}")->assertOk()->json();
        $this->assertSame('Hi Bob, let us connect.', $preview['preview']);
        $this->assertSame('incoming', $preview['direction']);
        $this->assertSame($alice->id, $preview['user']['id']);
        $this->assertCount(1, $preview['messages']);

        $sentPreview = $this->actingAs($alice)->getJson("/api/message-requests/{$requestId}")->assertOk()->json();
        $this->assertSame('sent', $sentPreview['direction']);

        // Non-friends cannot send unrestricted messages in the hidden thread.
        $this->actingAs($alice)
            ->postJson("/api/conversations/{$conversationId}/messages", ['body' => 'again'])
            ->assertForbidden();

        // Only the recipient may accept.
        $this->actingAs($eve)->postJson("/api/message-requests/{$requestId}/accept")->assertForbidden();
        $this->actingAs($bob)->postJson("/api/message-requests/{$requestId}/accept")
            ->assertOk()->assertJsonPath('status', 'accepted');

        $this->assertTrue($alice->fresh()->isFriendsWith($bob->id));
        $search = $this->actingAs($alice)->getJson('/api/users?q=bob')->assertOk()->json();
        $this->assertTrue(collect($search)->firstWhere('id', $bob->id)['is_friend']);
        $this->assertCount(1, $this->actingAs($alice)->getJson('/api/conversations')->json());
        $this->assertCount(1, $this->actingAs($bob)->getJson('/api/conversations')->json());

        // Now normal messaging works both ways.
        $this->actingAs($bob)->postJson("/api/conversations/{$conversationId}/messages", ['body' => 'Hello!'])
            ->assertCreated();
        $this->actingAs($alice)->postJson("/api/conversations/{$conversationId}/messages", ['body' => 'Hi!'])
            ->assertCreated();
    }

    public function test_deleted_request_cannot_continue_and_duplicates_are_rejected(): void
    {
        $alice = $this->userWithProfile('alice');
        $bob = $this->userWithProfile('bob');

        $first = $this->actingAs($alice)->postJson('/api/message-requests', [
            'user_id' => $bob->id, 'body' => 'First hello',
        ])->assertCreated();

        $this->actingAs($alice)->postJson('/api/message-requests', [
            'user_id' => $bob->id, 'body' => 'Again',
        ])->assertStatus(422);

        // The sender may not delete someone else's incoming request.
        $this->actingAs($alice)
            ->postJson("/api/message-requests/{$first->json('id')}/delete")
            ->assertForbidden();

        $this->actingAs($bob)->postJson("/api/message-requests/{$first->json('id')}/delete")
            ->assertOk()->assertJsonPath('status', 'deleted');

        $this->actingAs($alice)
            ->postJson("/api/conversations/{$first->json('conversation_id')}/messages", ['body' => 'still there?'])
            ->assertForbidden();
        $this->actingAs($alice)->postJson("/api/conversations/direct/{$bob->id}")->assertForbidden();

        $this->assertDatabaseHas('message_requests', ['id' => $first->json('id'), 'status' => 'deleted']);
    }

    public function test_friends_cannot_send_message_requests(): void
    {
        $alice = $this->userWithProfile('alice');
        $bob = $this->userWithProfile('bob');
        $this->befriend($alice, $bob);

        $this->actingAs($alice)->postJson('/api/message-requests', [
            'user_id' => $bob->id, 'body' => 'Hello',
        ])->assertStatus(422);

        $this->assertDatabaseCount('message_requests', 0);
        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_sender_can_cancel_their_own_request(): void
    {
        $alice = $this->userWithProfile('alice');
        $bob = $this->userWithProfile('bob');

        $request = $this->actingAs($alice)->postJson('/api/message-requests', [
            'user_id' => $bob->id, 'body' => 'Hello',
        ])->assertCreated();

        $this->actingAs($bob)->deleteJson("/api/message-requests/{$request->json('id')}")->assertForbidden();
        $this->actingAs($alice)->deleteJson("/api/message-requests/{$request->json('id')}")
            ->assertOk()->assertJsonPath('status', 'cancelled');

        $this->assertSame('cancelled', MessageRequest::first()->status);
        $this->assertDatabaseHas('conversations', ['id' => $request->json('conversation_id')]);
        $this->assertSame(1, Conversation::count()); // history is kept
    }
}
