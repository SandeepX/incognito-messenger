<?php

namespace Tests\Support;

use App\Models\Friendship;
use App\Models\User;
use App\Models\UserProfile;

trait CreatesUsers
{
    protected function userWithProfile(string $username, ?string $email = null): User
    {
        $user = User::factory()->create([
            'email' => $email ?? $username.'@example.com',
        ]);

        UserProfile::create([
            'user_id' => $user->id,
            'username' => $username,
        ]);

        return $user;
    }

    protected function befriend(User $a, User $b): void
    {
        Friendship::firstOrCreate(
            ['user_id' => $a->id, 'friend_id' => $b->id],
            ['pair_key' => $a->id.'-'.$b->id],
        );
        Friendship::firstOrCreate(
            ['user_id' => $b->id, 'friend_id' => $a->id],
            ['pair_key' => $b->id.'-'.$a->id],
        );
    }
}
