<?php

namespace App\Events;

use App\Models\Call;
use App\Support\CallService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CallEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Call $call,
        public string $eventName,
        public array $extra = [],
        public ?int $onlyUserId = null,
    ) {}

    public function broadcastOn(): array
    {
        if ($this->onlyUserId) {
            return [new PrivateChannel('users.'.$this->onlyUserId)];
        }

        return [
            new PrivateChannel('users.'.$this->call->caller_id),
            new PrivateChannel('users.'.$this->call->callee_id),
        ];
    }

    public function broadcastAs(): string
    {
        return $this->eventName;
    }

    public function broadcastWith(): array
    {
        return array_merge([
            'call' => CallService::payload($this->call),
        ], $this->extra);
    }
}
