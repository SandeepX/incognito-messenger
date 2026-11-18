<?php

namespace Tests\Support;

use App\Models\User;
use App\Models\UserProfile;

trait CreatesUsers
{
    /** Create a user that has the profile every messenger screen expects. */
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
}
