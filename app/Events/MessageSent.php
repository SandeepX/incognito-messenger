<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * One event for any 1:1 chat message.
 *
 * The channel is SYMMETRIC: both participants of a conversation
 * subscribe to the same channel, built by sorting the two user ids:
 *
 *     Alice(1) <-> Bob(2)  =>  private chat.1.2
 *
 * Sorting matters — otherwise Alice's client would listen on chat.1.2
 * while Bob sent to chat.2.1 and they'd never meet.
 */
class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $fromId,
        public string $fromName,
        public int $toId,
        public string $body,
    ) {}

    public function broadcastOn(): array
    {
        $ids = [$this->fromId, $this->toId];
        sort($ids);

        return [new PrivateChannel('chat.'.$ids[0].'.'.$ids[1])];
    }

    public function broadcastAs(): string
    {
        return 'MessageSent';
    }

    public function broadcastWith(): array
    {
        return [
            'from' => $this->fromId,
            'name' => $this->fromName,
            'to' => $this->toId,
            'body' => $this->body,
        ];
    }
}
