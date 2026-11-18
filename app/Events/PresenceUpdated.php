<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Lightweight presence update. The recipient list is limited to friends and
 * people who share a normal (non-pending) conversation, so privacy is enforced
 * on the server before anything is put on the wire.
 */
class PresenceUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** @param array<int, int> $audience */
    public function __construct(
        public User $user,
        public bool $online,
        public array $audience,
    ) {}

    public function broadcastOn(): array
    {
        return collect($this->audience)
            ->map(fn (int $id) => new PrivateChannel('user.'.$id))
            ->all();
    }

    public function broadcastAs(): string
    {
        return 'PresenceUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'user' => array_merge([
                'id' => (int) $this->user->id,
                'name' => $this->user->name,
                'avatar' => $this->user->avatarUrl(),
            ], $this->user->presencePayload()),
            'online' => $this->online,
        ];
    }
}
