<?php

use App\Events\MessageUnsent;
use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

function unsendMessageLegacyConversation($alice, $bob): int
{
    makeFriends($alice, $bob);

    return (int) test()->actingAs($alice)
        ->postJson("/api/conversations/direct/{$bob->id}")
        ->assertOk()
        ->json('id');
}

function unsendMessageLegacySend($alice, int $conversationId, string $body): int
{
    return (int) test()->actingAs($alice)
        ->postJson("/api/conversations/{$conversationId}/messages", ['body' => $body])
        ->assertCreated()
        ->json('id');
}

it('unsends an unseen message for both sides', function () {
    Event::fake([MessageUnsent::class]);

    $alice = $this->userWithProfile('alice');
    $bob = $this->userWithProfile('bob');
    $conversationId = unsendMessageLegacyConversation($alice, $bob);
    $messageId = unsendMessageLegacySend($alice, $conversationId, 'Secret text');

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
});

it('hides a seen message only from the sender', function () {
    Event::fake([MessageUnsent::class]);

    $alice = $this->userWithProfile('alice');
    $bob = $this->userWithProfile('bob');
    $conversationId = unsendMessageLegacyConversation($alice, $bob);
    $messageId = unsendMessageLegacySend($alice, $conversationId, 'Already seen');

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

    Event::assertNotDispatched(MessageUnsent::class);

    $aliceHistory = $this->actingAs($alice)->getJson("/api/conversations/{$conversationId}")->assertOk()->json();
    $this->assertCount(0, $aliceHistory['data']);

    $aliceSidebar = $this->actingAs($alice)->getJson('/api/conversations')->assertOk()->json();
    $this->assertNull($aliceSidebar[0]['last_message']);

    $bobHistory = $this->actingAs($bob)->getJson("/api/conversations/{$conversationId}")->assertOk()->json();
    $this->assertCount(1, $bobHistory['data']);
    $this->assertSame('Already seen', $bobHistory['data'][0]['body']);
});

it('only lets the sender unsend a message', function () {
    $alice = $this->userWithProfile('alice');
    $bob = $this->userWithProfile('bob');
    $conversationId = unsendMessageLegacyConversation($alice, $bob);
    $messageId = unsendMessageLegacySend($alice, $conversationId, 'Hands off');

    $this->actingAs($bob)
        ->postJson("/api/messages/{$messageId}/unsend", ['mode' => 'everyone'])
        ->assertForbidden();

    $this->assertDatabaseHas('messages', [
        'id' => $messageId,
        'body' => 'Hands off',
        'is_unsent' => 0,
    ]);
});

it('removes private attachments from storage when unsending for everyone', function () {
    Storage::fake('minio');

    $alice = $this->userWithProfile('alice');
    $bob = $this->userWithProfile('bob');
    $conversationId = unsendMessageLegacyConversation($alice, $bob);
    $messageId = unsendMessageLegacySend($alice, $conversationId, 'See attached');

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
});
