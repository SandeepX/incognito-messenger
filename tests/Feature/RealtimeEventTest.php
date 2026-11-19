<?php

use App\Events\ConversationsUpdated;
use App\Events\MessageSent;
use App\Events\NotificationCreated;
use Illuminate\Support\Facades\Event;

it('pushes notifications for friend requests and acceptance', function () {
    Event::fake([NotificationCreated::class, ConversationsUpdated::class]);

    $alice = $this->userWithProfile('alice');
    $bob = $this->userWithProfile('bob');

    $this->actingAs($alice)->postJson('/api/friends', ['user_id' => $bob->id])->assertCreated();

    Event::assertDispatched(
        NotificationCreated::class,
        fn (NotificationCreated $e) => $e->notification->user_id === $bob->id
            && $e->notification->type === 'friend_request_received'
            && $e->broadcastOn()->name === "private-user.{$bob->id}",
    );

    $requestId = $bob->receivedFriendRequests()->first()->id;
    $this->actingAs($bob)->postJson("/api/friends/{$requestId}/accept")->assertOk();

    Event::assertDispatched(
        NotificationCreated::class,
        fn (NotificationCreated $e) => $e->notification->user_id === $alice->id
            && $e->notification->type === 'friend_request_accepted',
    );
    Event::assertDispatched(ConversationsUpdated::class, 2);
});

it('pushes message, conversation, and notification events for a message request', function () {
    Event::fake([MessageSent::class, ConversationsUpdated::class, NotificationCreated::class]);

    $alice = $this->userWithProfile('alice');
    $bob = $this->userWithProfile('bob');

    $response = $this->actingAs($alice)->postJson('/api/message-requests', [
        'user_id' => $bob->id,
        'body' => 'Can we talk?',
    ])->assertCreated();

    $conversationId = $response->json('conversation_id');

    Event::assertDispatched(
        MessageSent::class,
        fn (MessageSent $e) => $e->message->conversation_id === $conversationId
            && $e->broadcastOn()[0]->name === "private-conversation.{$conversationId}",
    );
    Event::assertDispatched(ConversationsUpdated::class, 2);
    Event::assertDispatched(
        NotificationCreated::class,
        fn (NotificationCreated $e) => $e->notification->user_id === $bob->id
            && $e->notification->type === 'message_request_received',
    );
});

it('notifies every friend on the users private channel for notes', function () {
    Event::fake([NotificationCreated::class]);

    $alice = $this->userWithProfile('alice');
    $bob = $this->userWithProfile('bob');
    $charlie = $this->userWithProfile('charlie');
    $eve = $this->userWithProfile('eve');
    $this->befriend($alice, $bob);
    $this->befriend($alice, $charlie);

    $this->actingAs($alice)->postJson('/api/notes', ['content' => 'Hello friends'])->assertCreated();

    Event::assertDispatchedTimes(NotificationCreated::class, 2);
    Event::assertDispatched(
        NotificationCreated::class,
        fn (NotificationCreated $e) => $e->notification->user_id === $bob->id
            && $e->notification->type === 'note_posted'
            && $e->broadcastOn()->name === "private-user.{$bob->id}",
    );
    Event::assertDispatched(
        NotificationCreated::class,
        fn (NotificationCreated $e) => $e->notification->user_id === $charlie->id,
    );
    Event::assertNotDispatched(
        NotificationCreated::class,
        fn (NotificationCreated $e) => $e->notification->user_id === $eve->id,
    );
});

it('keeps new event channels under existing private authorization', function () {
    $alice = $this->userWithProfile('alice');
    $bob = $this->userWithProfile('bob');
    $eve = $this->userWithProfile('eve');
    $this->befriend($alice, $bob);

    $conversationId = $this->actingAs($alice)
        ->postJson("/api/conversations/direct/{$bob->id}")->json('id');

    $this->assertTrue($this->channelAllows('user.{id}', $alice, $alice->id));
    $this->assertFalse($this->channelAllows('user.{id}', $alice, $bob->id));
    $this->assertTrue($this->channelAllows('conversation.{id}', $bob, $conversationId));
    $this->assertFalse($this->channelAllows('conversation.{id}', $eve, $conversationId));
});
