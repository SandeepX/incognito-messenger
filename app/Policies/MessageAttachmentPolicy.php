<?php

namespace App\Policies;

use App\Models\MessageAttachment;
use App\Models\User;

class MessageAttachmentPolicy
{
    public function view(User $user, MessageAttachment $attachment): bool
    {
        $conversation = $attachment->message?->conversation;

        if (! $conversation) {
            return false;
        }

        if ($conversation->isPendingRequest()) {
            $request = $conversation->messageRequest;

            return $user->id === $request?->sender_id || $user->id === $request?->recipient_id;
        }

        if ($conversation->type === 'direct') {
            return $conversation->isParticipant($user->id);
        }

        return $conversation->group?->isMember($user->id) ?? false;
    }
}
