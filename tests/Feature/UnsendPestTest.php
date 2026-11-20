<?php

use App\Events\ConversationsUpdated;
use App\Events\MessageUnsent;
use App\Events\UnreadCountsUpdated;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Support\UnsendPolicy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->alice = makeUser('alice');
    $this->bob = makeUser('bob');
    $this->eve = makeUser('eve');
    makeFriends($this->alice, $this->bob);
    $this->conversation = createConversation($this->alice, $this->bob);

    $this->send = function (string $body, $sender = null): int {
        return (int) actingAsUser($sender ?? $this->alice)->postJson(
            "/api/conversations/{$this->conversation->id}/messages",
            ['body' => $body],
        )->assertCreated()->json('id');
    };
});

describe('delete for everyone on an unseen message', function () {
    it('turns the message into a placeholder for both users in the same position', function () {
        Event::fake([MessageUnsent::class]);

        $first = ($this->send)('First');
        $target = ($this->send)('Secret text');
        $last = ($this->send)('Last');

        $response = actingAsUser($this->alice)
            ->postJson("/api/messages/{$target}/unsend", ['mode' => 'everyone'])
            ->assertOk()
            ->assertJsonPath('mode', 'everyone')
            ->assertJsonPath('message.id', $target)
            ->assertJsonPath('message.is_unsent', true)
            ->assertJsonPath('message.unsent_by', $this->alice->id)
            ->assertJsonPath('message.body', '')
            ->assertJsonPath('hidden_for', [])
            ->json();

        $this->assertDatabaseHas('messages', [
            'id' => $target,
            'body' => '',
            'is_unsent' => 1,
            'unsent_by' => $this->alice->id,
        ]);

        foreach ([$this->alice, $this->bob] as $viewer) {
            $history = actingAsUser($viewer)
                ->getJson("/api/conversations/{$this->conversation->id}")
                ->assertOk()
                ->json('data');

            $ids = array_column($history, 'id');
            $placeholder = collect($history)->firstWhere('id', $target);

            expect($ids)->toBe([$last, $target, $first])
                ->and($placeholder['id'])->toBe($target)
                ->and($placeholder['is_unsent'])->toBeTrue()
                ->and($placeholder['body'])->toBe('')
                ->and($placeholder['attachments'])->toBe([]);
        }

        Event::assertDispatched(MessageUnsent::class, function (MessageUnsent $event) use ($target) {
            return $event->message->id === $target
                && $event->mode === 'everyone'
                && $event->broadcastOn()[0]->name === "private-conversation.{$this->conversation->id}"
                && $event->broadcastWith()['message']['is_unsent'] === true;
        });
    });

    it('clears attachments from the row and from private storage', function () {
        Storage::fake('minio');

        $messageId = ($this->send)('With a file', $this->alice);
        $path = "conversations/{$this->conversation->id}/files/secret.txt";
        Storage::disk('minio')->put($path, 'private bytes');

        MessageAttachment::factory()->create([
            'message_id' => $messageId,
            'storage_path' => $path,
            'original_filename' => 'secret.txt',
        ]);

        actingAsUser($this->alice)
            ->postJson("/api/messages/{$messageId}/unsend", ['mode' => 'everyone'])
            ->assertOk();

        $this->assertDatabaseMissing('message_attachments', ['message_id' => $messageId]);
        Storage::disk('minio')->assertMissing($path);
    });

    it('is idempotent and broadcasts only once', function () {
        Event::fake([MessageUnsent::class]);

        $messageId = ($this->send)('Retry me');

        actingAsUser($this->alice)
            ->postJson("/api/messages/{$messageId}/unsend", ['mode' => 'everyone'])
            ->assertOk();
        actingAsUser($this->alice)
            ->postJson("/api/messages/{$messageId}/unsend", ['mode' => 'everyone'])
            ->assertOk();

        Event::assertDispatchedTimes(MessageUnsent::class, 1);
    });
});

describe('delete for everyone on a seen message', function () {
    it('rejects the request with the current seen state', function () {
        Event::fake([MessageUnsent::class]);

        $messageId = ($this->send)('Already seen');

        actingAsUser($this->bob)
            ->postJson("/api/messages/{$messageId}/seen")
            ->assertOk();

        actingAsUser($this->alice)
            ->postJson("/api/messages/{$messageId}/unsend", ['mode' => 'everyone'])
            ->assertStatus(422)
            ->assertJsonPath('message', UnsendPolicy::SEEN_MESSAGE);

        $this->assertDatabaseHas('messages', [
            'id' => $messageId,
            'body' => 'Already seen',
            'is_unsent' => 0,
        ]);

        Event::assertNotDispatched(MessageUnsent::class);
    });

    it('uses the seen state even when the delete races the read receipt', function () {
        $messageId = ($this->send)('Race');

        actingAsUser($this->bob)->getJson("/api/conversations/{$this->conversation->id}")->assertOk();

        actingAsUser($this->alice)
            ->postJson("/api/messages/{$messageId}/unsend", ['mode' => 'everyone'])
            ->assertStatus(422);

        expect(Message::findOrFail($messageId)->is_unsent)->toBeFalse();
    });
});

describe('delete for me', function () {
    it('hides the message only for the caller while the recipient keeps it', function () {
        Event::fake([MessageUnsent::class, ConversationsUpdated::class, UnreadCountsUpdated::class]);

        $messageId = ($this->send)('Only for me');
        actingAsUser($this->bob)->getJson("/api/conversations/{$this->conversation->id}")->assertOk();

        Event::fake([MessageUnsent::class, ConversationsUpdated::class, UnreadCountsUpdated::class]);

        $response = actingAsUser($this->alice)
            ->postJson("/api/messages/{$messageId}/unsend", ['mode' => 'me'])
            ->assertOk()
            ->assertJsonPath('mode', 'me')
            ->assertJsonPath('hidden_for', [$this->alice->id])
            ->json();

        expect($response['id'])->toBe($messageId)
            ->and(Message::findOrFail($messageId)->deleted_for)->toBe([$this->alice->id]);

        Event::assertNotDispatched(MessageUnsent::class);
        Event::assertNotDispatched(
            ConversationsUpdated::class,
            fn (ConversationsUpdated $event) => $event->userId === $this->bob->id,
        );
        Event::assertNotDispatched(
            UnreadCountsUpdated::class,
            fn (UnreadCountsUpdated $event) => $event->userId === $this->bob->id,
        );

        $aliceHistory = actingAsUser($this->alice)
            ->getJson("/api/conversations/{$this->conversation->id}")->json('data');
        $bobHistory = actingAsUser($this->bob)
            ->getJson("/api/conversations/{$this->conversation->id}")->json('data');

        expect($aliceHistory)->toBe([])
            ->and($bobHistory)->toHaveCount(1)
            ->and($bobHistory[0]['body'])->toBe('Only for me')
            ->and($bobHistory[0]['is_unsent'])->toBeFalse();
    });

    it('allows either participant to hide a message from their own history', function () {
        $messageId = ($this->send)('Bob can hide this too');
        actingAsUser($this->alice)->getJson("/api/conversations/{$this->conversation->id}")->assertOk();

        actingAsUser($this->bob)
            ->postJson("/api/messages/{$messageId}/unsend", ['mode' => 'me'])
            ->assertOk()
            ->assertJsonPath('hidden_for', [$this->bob->id]);

        expect(Message::findOrFail($messageId)->isDeletedFor($this->bob->id))->toBeTrue()
            ->and(Message::findOrFail($messageId)->isDeletedFor($this->alice->id))->toBeFalse();

        expect(actingAsUser($this->alice)
            ->getJson("/api/conversations/{$this->conversation->id}")->json('data'))->toHaveCount(1);
    });
});

describe('authorization and validation', function () {
    it('only lets the sender delete for everyone', function () {
        $messageId = ($this->send)('Hands off');

        actingAsUser($this->bob)
            ->postJson("/api/messages/{$messageId}/unsend", ['mode' => 'everyone'])
            ->assertForbidden();

        actingAsUser($this->eve)
            ->postJson("/api/messages/{$messageId}/unsend", ['mode' => 'me'])
            ->assertForbidden();

        $this->assertDatabaseHas('messages', [
            'id' => $messageId,
            'body' => 'Hands off',
            'is_unsent' => 0,
        ]);
    });

    it('rejects an unknown delete mode', function () {
        $messageId = ($this->send)('Mode');

        actingAsUser($this->alice)
            ->postJson("/api/messages/{$messageId}/unsend", ['mode' => 'sometimes'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Choose whether to delete for everyone or only for yourself.');
    });

    it('cannot mark an unsent placeholder as seen', function () {
        $messageId = ($this->send)('Gone');

        actingAsUser($this->alice)
            ->postJson("/api/messages/{$messageId}/unsend", ['mode' => 'everyone'])
            ->assertOk();

        actingAsUser($this->bob)
            ->postJson("/api/messages/{$messageId}/seen")
            ->assertStatus(422);
    });
});

describe('previews and unread counts after unsending', function () {
    it('shows the unsent preview in both chat lists', function () {
        ($this->send)('Last message');

        $messageId = Message::latest('id')->value('id');

        actingAsUser($this->alice)
            ->postJson("/api/messages/{$messageId}/unsend", ['mode' => 'everyone'])
            ->assertOk();

        $aliceRow = collect(actingAsUser($this->alice)->getJson('/api/conversations')->json())
            ->firstWhere('id', $this->conversation->id);
        $bobRow = collect(actingAsUser($this->bob)->getJson('/api/conversations')->json())
            ->firstWhere('id', $this->conversation->id);

        expect($aliceRow['last_message']['preview'])->toBe('You unsent a message')
            ->and($bobRow['last_message']['preview'])->toBe('Alice unsent a message');
    });

    it('does not leave an unread badge behind', function () {
        $messageId = ($this->send)('Never seen');

        $before = collect(actingAsUser($this->bob)->getJson('/api/conversations')->json())
            ->firstWhere('id', $this->conversation->id);
        expect($before['unread'])->toBe(1);

        actingAsUser($this->alice)
            ->postJson("/api/messages/{$messageId}/unsend", ['mode' => 'everyone'])
            ->assertOk();

        $after = collect(actingAsUser($this->bob)->getJson('/api/conversations')->json())
            ->firstWhere('id', $this->conversation->id);

        expect($after['unread'])->toBe(0);
    });
});
