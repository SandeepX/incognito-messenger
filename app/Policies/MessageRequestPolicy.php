<?php

namespace App\Policies;

use App\Models\MessageRequest;
use App\Models\User;

class MessageRequestPolicy
{
    public function view(User $user, MessageRequest $request): bool
    {
        return $user->id === $request->sender_id || $user->id === $request->recipient_id;
    }

    public function respond(User $user, MessageRequest $request): bool
    {
        return $user->id === $request->recipient_id && $request->status === 'pending';
    }

    public function cancel(User $user, MessageRequest $request): bool
    {
        return $user->id === $request->sender_id && $request->status === 'pending';
    }
}
