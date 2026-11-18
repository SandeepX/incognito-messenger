<?php

namespace App\Policies;

use App\Models\Note;
use App\Models\User;

class NotePolicy
{
    public function view(User $user, Note $note): bool
    {
        if (! $note->expires_at?->isFuture()) {
            return false;
        }

        if ((int) $user->id === (int) $note->user_id) {
            return true;
        }

        $note->loadMissing('user.profile');

        return $note->user?->canShowNoteTo((int) $user->id, $note) ?? false;
    }

    public function update(User $user, Note $note): bool
    {
        return (int) $user->id === (int) $note->user_id;
    }

    public function delete(User $user, Note $note): bool
    {
        return (int) $user->id === (int) $note->user_id;
    }
}
