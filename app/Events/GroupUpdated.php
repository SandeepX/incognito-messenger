<?php

namespace App\Events;

use App\Models\Group;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GroupUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Group $group,
        public string $reason = 'updated',
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('conversation.'.$this->group->conversation->id)];
    }

    public function broadcastAs(): string
    {
        return 'GroupUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'group_id' => $this->group->id,
            'reason' => $this->reason,
            'name' => $this->group->name,
            'avatar' => $this->group->avatar_path
                ? route('avatars.show', ['filename' => basename($this->group->avatar_path)])
                : asset('images/default-avatar.svg'),
            'member_count' => $this->group->members()->count(),
        ];
    }
}
