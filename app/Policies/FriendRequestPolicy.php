<?php

namespace App\Policies;

use App\Models\FriendRequest;
use App\Models\User;

class FriendRequestPolicy
{
    public function view(User $user, FriendRequest $request): bool
    {
        return $user->id === $request->sender_id || $user->id === $request->recipient_id;
    }

    public function respond(User $user, FriendRequest $request): bool
    {
        return $user->id === $request->recipient_id && $request->status === 'pending';
    }

    public function cancel(User $user, FriendRequest $request): bool
    {
        return $user->id === $request->sender_id && $request->status === 'pending';
    }
}
