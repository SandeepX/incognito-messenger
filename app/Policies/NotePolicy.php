<?php

namespace App\Policies;

use App\Models\Note;
use App\Models\User;

class NotePolicy
{
    public function view(User $user, Note $note): bool
    {
        return $note->expires_at?->isFuture()
            && ($user->id === $note->user_id || $user->isFriendsWith((int) $note->user_id));
    }

    public function update(User $user, Note $note): bool
    {
        return $user->id === $note->user_id;
    }

    public function delete(User $user, Note $note): bool
    {
        return $user->id === $note->user_id;
    }
}
