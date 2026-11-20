<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            'Alice' => ['alice', 'Say hello to Alice 👋'],
            'Bob' => ['bob', 'Always up for a chat.'],
            'Charlie' => ['charlie', 'Coffee first, then messages.'],
            'Dana' => ['dana', 'Building things on the web.'],
        ];

        foreach ($accounts as $name => [$username, $bio]) {
            $user = User::firstOrCreate(
                ['email' => strtolower($name).'@example.com'],
                ['name' => $name, 'password' => 'password'],
            );

            UserProfile::firstOrCreate(
                ['user_id' => $user->id],
                ['username' => $username, 'bio' => $bio],
            );
        }
    }
}
