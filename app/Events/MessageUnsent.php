<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageUnsent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Message $message,
        public string $mode,
        public array $hiddenFor = [],
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('conversation.'.$this->message->conversation_id)];
    }

    public function broadcastAs(): string
    {
        return 'MessageUnsent';
    }

    public function broadcastWith(): array
    {
        $base = [
            'id' => (int) $this->message->id,
            'message_id' => (int) $this->message->id,
            'conversation_id' => (int) $this->message->conversation_id,
            'sender_id' => (int) $this->message->sender_id,
            'unsent_by' => $this->message->unsent_by ? (int) $this->message->unsent_by : null,
            'unsent_at' => $this->message->unsent_at?->toISOString(),
            'mode' => $this->mode,
            'hidden_for' => array_values(array_map('intval', $this->hiddenFor)),
        ];

        if ($this->mode === 'everyone') {
            $base['message'] = $this->message->payload();
        }

        return $base;
    }
}
