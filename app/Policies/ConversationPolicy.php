<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

class ConversationPolicy
{
    /** Only participants may read a thread or send messages to it. */
    public function view(User $user, Conversation $conversation): bool
    {
        if ($conversation->type === 'direct') {
            return $conversation->isParticipant($user->id);
        }

        return $conversation->group?->isMember($user->id) ?? false;
    }

    public function send(User $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }
}
