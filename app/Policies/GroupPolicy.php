<?php

namespace App\Policies;

use App\Models\Group;
use App\Models\User;

class GroupPolicy
{
    public function view(User $user, Group $group): bool
    {
        return $group->isMember($user->id);
    }

    public function manage(User $user, Group $group): bool
    {
        return $group->isAdmin($user->id);
    }

    public function leave(User $user, Group $group): bool
    {
        return $group->creator_id !== $user->id && $group->isMember($user->id);
    }
}
