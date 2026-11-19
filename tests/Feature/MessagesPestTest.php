<?php

use App\Events\MessageSeen;
use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->alice = makeUser('alice');
    $this->bob = makeUser('bob');
    $this->eve = makeUser('eve');
    makeFriends($this->alice, $this->bob);
    $this->conversation = createConversation($this->alice, $this->bob);
});

describe('sending messages', function () {
    it('persists the authenticated sender', function () {
        actingAsUser($this->alice)->postJson(
            "/api/conversations/{$this->conversation->id}/messages",
            ['body' => 'Hello Bob'],
        )->assertCreated()
            ->assertJsonPath('sender_id', $this->alice->id)
            ->assertJsonPath('body', 'Hello Bob')
            ->assertJsonPath('status', 'sent');

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $this->conversation->id,
            'sender_id' => $this->alice->id,
            'body' => 'Hello Bob',
        ]);
    });

    it('forbids non-participants from reading or sending', function () {
        actingAsUser($this->eve)->getJson("/api/conversations/{$this->conversation->id}")
            ->assertForbidden();

        actingAsUser($this->eve)->postJson(
            "/api/conversations/{$this->conversation->id}/messages",
            ['body' => 'Intruder'],
        )->assertForbidden();

        $this->assertDatabaseCount('messages', 0);
    });

    it('rejects an empty message without an attachment', function () {
        actingAsUser($this->alice)->postJson(
            "/api/conversations/{$this->conversation->id}/messages",
            ['body' => '   '],
        )->assertStatus(422)->assertJsonValidationErrors('body');
    });
});

describe('attachments', function () {
    it('stores allowed attachments on the private disk', function () {
        Storage::fake('minio');

        $response = actingAsUser($this->alice)->postJson(
            "/api/conversations/{$this->conversation->id}/messages",
            ['attachments' => [UploadedFile::fake()->create('report.pdf', 100, 'application/pdf')]],
        )->assertCreated()->json();

        $attachment = MessageAttachment::where('message_id', $response['id'])->firstOrFail();

        Storage::disk('minio')->assertExists($attachment->storage_path);
        expect($attachment->original_filename)->toBe('report.pdf')
            ->and($response['attachments'])->toHaveCount(1);
    });

    it('rejects attachments over 20 MB', function () {
        Storage::fake('minio');

        actingAsUser($this->alice)->postJson(
            "/api/conversations/{$this->conversation->id}/messages",
            ['body' => 'Too big', 'attachments' => [
                UploadedFile::fake()->create('archive.zip', 20481, 'application/zip'),
            ]],
        )->assertStatus(422)->assertJsonValidationErrors('attachments.0');

        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('message_attachments', 0);
    });

    it('rejects unsupported attachment types', function () {
        Storage::fake('minio');

        actingAsUser($this->alice)->postJson(
            "/api/conversations/{$this->conversation->id}/messages",
            ['attachments' => [
                UploadedFile::fake()->create('script.php', 1, 'application/x-php'),
            ]],
        )->assertStatus(422)->assertJsonValidationErrors('attachments.0');

        $this->assertDatabaseCount('messages', 0);
    });
});

describe('read receipts', function () {
    it('marks messages seen when the recipient opens the chat', function () {
        Event::fake([MessageSeen::class]);

        $sent = actingAsUser($this->alice)->postJson(
            "/api/conversations/{$this->conversation->id}/messages",
            ['body' => 'Did you see this?'],
        )->assertCreated()->json();

        expect($sent['status'])->toBe('sent')
            ->and($sent['seen_by'])->toBe([]);

        $history = actingAsUser($this->bob)
            ->getJson("/api/conversations/{$this->conversation->id}")
            ->assertOk()
            ->json();

        $received = collect($history['data'])->firstWhere('id', $sent['id']);

        expect($received['status'])->toBe('seen')
            ->and($received['seen_by'][0]['user_id'])->toBe($this->bob->id);

        $this->assertDatabaseHas('message_reads', [
            'message_id' => $sent['id'],
            'user_id' => $this->bob->id,
        ]);

        Event::assertDispatched(MessageSeen::class, function (MessageSeen $event) use ($sent) {
            return $event->read->message_id === $sent['id']
                && $event->read->user_id === $this->bob->id
                && $event->broadcastOn()->name === "private-conversation.{$this->conversation->id}";
        });
    });

    it('is idempotent and does not duplicate receipts or events', function () {
        Event::fake([MessageSeen::class]);

        $messageId = actingAsUser($this->alice)->postJson(
            "/api/conversations/{$this->conversation->id}/messages",
            ['body' => 'Once'],
        )->json('id');

        actingAsUser($this->bob)->getJson("/api/conversations/{$this->conversation->id}")->assertOk();
        actingAsUser($this->bob)->getJson("/api/conversations/{$this->conversation->id}")->assertOk();

        expect(Message::findOrFail($messageId)->reads()->where('user_id', $this->bob->id)->count())->toBe(1);
        Event::assertDispatchedTimes(MessageSeen::class, 1);
    });

    it('exposes sent then seen status values', function () {
        $messageId = actingAsUser($this->alice)->postJson(
            "/api/conversations/{$this->conversation->id}/messages",
            ['body' => 'Status check'],
        )->assertJsonPath('status', 'sent')->json('id');

        actingAsUser($this->bob)->getJson("/api/conversations/{$this->conversation->id}")->assertOk();

        $senderView = collect(
            actingAsUser($this->alice)->getJson("/api/conversations/{$this->conversation->id}")->json('data'),
        )->firstWhere('id', $messageId);

        expect($senderView['status'])->toBe('seen')
            ->and($senderView['seen_by'][0]['user_id'])->toBe($this->bob->id);
    });

    it('does not let a sender mark their own message seen or outsiders mark any message', function () {
        $messageId = actingAsUser($this->alice)->postJson(
            "/api/conversations/{$this->conversation->id}/messages",
            ['body' => 'Mine'],
        )->json('id');

        actingAsUser($this->alice)->postJson("/api/messages/{$messageId}/seen")->assertStatus(422);
        actingAsUser($this->eve)->postJson("/api/messages/{$messageId}/seen")->assertForbidden();
    });

    it('tracks unread counts until the recipient opens the chat', function () {
        actingAsUser($this->alice)->postJson(
            "/api/conversations/{$this->conversation->id}/messages",
            ['body' => 'Unread one'],
        )->assertCreated();
        actingAsUser($this->alice)->postJson(
            "/api/conversations/{$this->conversation->id}/messages",
            ['body' => 'Unread two'],
        )->assertCreated();

        $before = collect(actingAsUser($this->bob)->getJson('/api/conversations')->json())
            ->firstWhere('id', $this->conversation->id);

        expect($before['unread'])->toBe(2);

        actingAsUser($this->bob)->getJson("/api/conversations/{$this->conversation->id}")->assertOk();

        $after = collect(actingAsUser($this->bob)->getJson('/api/conversations')->json())
            ->firstWhere('id', $this->conversation->id);

        expect($after['unread'])->toBe(0);
    });
});
