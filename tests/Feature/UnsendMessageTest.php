<?php

namespace Tests\Feature;

use App\Events\MessageUnsent;
use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

class UnsendMessageTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private function conversation($alice, $bob): int
    {
        $this->befriend($alice, $bob);

        return (int) $this->actingAs($alice)
            ->postJson("/api/conversations/direct/{$bob->id}")
            ->assertOk()
            ->json('id');
    }

    private function send($alice, int $conversationId, string $body): int
    {
        return (int) $this->actingAs($alice)
            ->postJson("/api/conversations/{$conversationId}/messages", ['body' => $body])
            ->assertCreated()
            ->json('id');
    }

    public function test_unseen_message_is_unsent_for_both_sides(): void
    {
        Event::fake([MessageUnsent::class]);

        $alice = $this->userWithProfile('alice');
        $bob = $this->userWithProfile('bob');
        $conversationId = $this->conversation($alice, $bob);
        $messageId = $this->send($alice, $conversationId, 'Secret text');

        $response = $this->actingAs($alice)
            ->postJson("/api/messages/{$messageId}/unsend", ['mode' => 'everyone'])
            ->assertOk()
            ->assertJsonPath('mode', 'everyone')
            ->assertJsonPath('message.is_unsent', true)
            ->assertJsonPath('message.unsent_by', $alice->id)
            ->assertJsonPath('message.body', '')
            ->json();

        $this->assertSame([], $response['hidden_for']);
        $this->assertDatabaseHas('messages', [
            'id' => $messageId,
            'body' => '',
            'is_unsent' => 1,
            'unsent_by' => $alice->id,
        ]);

        Event::assertDispatched(MessageUnsent::class, function (MessageUnsent $event) use ($messageId, $conversationId) {
            return $event->message->id === $messageId
                && $event->mode === 'everyone'
                && $event->broadcastOn()[0]->name === "private-conversation.{$conversationId}";
        });

        // An unsent placeholder never leaves an unread badge behind.
        $bobSidebar = $this->actingAs($bob)->getJson('/api/conversations')->assertOk()->json();
        $this->assertSame(0, $bobSidebar[0]['unread']);
        $this->assertSame($alice->name.' unsent a message', $bobSidebar[0]['last_message']['preview']);

        $bobHistory = $this->actingAs($bob)->getJson("/api/conversations/{$conversationId}")->assertOk()->json();
        $this->assertCount(1, $bobHistory['data']);
        $this->assertSame($alice->name.' unsent a message', $bobHistory['data'][0]['preview']);
        $this->assertSame('', $bobHistory['data'][0]['body']);
        $this->assertSame([], $bobHistory['data'][0]['attachments']);

        $aliceHistory = $this->actingAs($alice)->getJson("/api/conversations/{$conversationId}")->assertOk()->json();
        $this->assertCount(1, $aliceHistory['data']);
        $this->assertSame('You unsent a message', $aliceHistory['data'][0]['preview']);
    }

    public function test_seen_message_is_hidden_only_from_the_sender(): void
    {
        Event::fake([MessageUnsent::class]);

        $alice = $this->userWithProfile('alice');
        $bob = $this->userWithProfile('bob');
        $conversationId = $this->conversation($alice, $bob);
        $messageId = $this->send($alice, $conversationId, 'Already seen');

        // Opening the chat creates Bob's per-message read receipt.
        $this->actingAs($bob)->getJson("/api/conversations/{$conversationId}")->assertOk();
        $this->assertDatabaseHas('message_reads', [
            'message_id' => $messageId,
            'user_id' => $bob->id,
        ]);

        $response = $this->actingAs($alice)
            ->postJson("/api/messages/{$messageId}/unsend", ['mode' => 'me'])
            ->assertOk()
            ->assertJsonPath('mode', 'me')
            ->json();

        $this->assertSame([$alice->id], $response['hidden_for']);
        $this->assertDatabaseHas('messages', [
            'id' => $messageId,
            'body' => 'Already seen',
            'is_unsent' => 0,
        ]);
        $this->assertContains($alice->id, Message::findOrFail($messageId)->deleted_for);

        // Local deletes never announce an event to the other participant.
        Event::assertNotDispatched(MessageUnsent::class);

        $aliceHistory = $this->actingAs($alice)->getJson("/api/conversations/{$conversationId}")->assertOk()->json();
        $this->assertCount(0, $aliceHistory['data']);

        $aliceSidebar = $this->actingAs($alice)->getJson('/api/conversations')->assertOk()->json();
        $this->assertNull($aliceSidebar[0]['last_message']);

        // The recipient keeps the original message untouched.
        $bobHistory = $this->actingAs($bob)->getJson("/api/conversations/{$conversationId}")->assertOk()->json();
        $this->assertCount(1, $bobHistory['data']);
        $this->assertSame('Already seen', $bobHistory['data'][0]['body']);
    }

    public function test_only_the_sender_can_unsend(): void
    {
        $alice = $this->userWithProfile('alice');
        $bob = $this->userWithProfile('bob');
        $conversationId = $this->conversation($alice, $bob);
        $messageId = $this->send($alice, $conversationId, 'Hands off');

        $this->actingAs($bob)
            ->postJson("/api/messages/{$messageId}/unsend", ['mode' => 'everyone'])
            ->assertForbidden();

        $this->assertDatabaseHas('messages', [
            'id' => $messageId,
            'body' => 'Hands off',
            'is_unsent' => 0,
        ]);
    }

    public function test_global_unsend_removes_private_attachments_from_storage(): void
    {
        Storage::fake('minio');

        $alice = $this->userWithProfile('alice');
        $bob = $this->userWithProfile('bob');
        $conversationId = $this->conversation($alice, $bob);
        $messageId = $this->send($alice, $conversationId, 'See attached');

        $path = "conversations/{$conversationId}/files/secret.txt";
        Storage::disk('minio')->put($path, 'private bytes');
        MessageAttachment::create([
            'message_id' => $messageId,
            'original_filename' => 'secret.txt',
            'storage_path' => $path,
            'mime_type' => 'text/plain',
            'file_size' => 13,
            'attachment_type' => 'file',
        ]);

        $this->actingAs($alice)->postJson("/api/messages/{$messageId}/unsend", ['mode' => 'everyone'])->assertOk();

        $this->assertDatabaseMissing('message_attachments', ['message_id' => $messageId]);
        Storage::disk('minio')->assertMissing($path);
    }
}
