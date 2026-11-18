<?php

namespace Tests\Feature;

use App\Events\ConversationsUpdated;
use App\Events\MessageSent;
use App\Events\NotificationCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\ChecksChannels;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

class RealtimeEventTest extends TestCase
{
    use ChecksChannels, CreatesUsers, RefreshDatabase;

    public function test_friend_request_and_acceptance_push_notifications(): void
    {
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
    }

    public function test_message_request_pushes_message_conversation_and_notification_events(): void
    {
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
    }

    public function test_notes_notify_every_friend_on_the_users_private_channel(): void
    {
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
    }

    public function test_new_event_channels_still_use_existing_private_authorization(): void
    {
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
    }
}
