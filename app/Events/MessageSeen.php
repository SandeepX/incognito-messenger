<?php

namespace App\Events;

use App\Models\MessageRead;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSeen implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public MessageRead $read, public array $counts = []) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('conversation.'.$this->read->message->conversation_id);
    }

    public function broadcastAs(): string
    {
        return 'MessageSeen';
    }

    public function broadcastWith(): array
    {
        $user = $this->read->user;

        return [
            'message_id' => (int) $this->read->message_id,
            'conversation_id' => (int) $this->read->message->conversation_id,
            'user_id' => (int) $this->read->user_id,
            'name' => $user?->name,
            'avatar' => $user?->avatarUrl(),
            'read_at' => $this->read->read_at?->toISOString(),
            'counts' => $this->counts,
        ];
    }
}
