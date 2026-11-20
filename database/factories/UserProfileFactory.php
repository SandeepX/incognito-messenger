<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class UserProfileFactory extends Factory
{
    protected $model = UserProfile::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'username' => Str::limit(
                Str::slug(fake()->unique()->userName(), '_'),
                30,
                '',
            ),
            'status' => null,
            'bio' => null,
            'show_active_status' => true,
            'message_sounds' => true,
            'message_sounds_volume' => 100,
            'theme_preference' => 'system',
            'desktop_notifications' => false,
            'read_receipts' => true,
            'message_request_policy' => 'everyone',
            'notes_visibility' => 'friends',
            'allow_calls' => 'friends',
            'call_ringtone' => true,
        ];
    }

    public function hiddenPresence(): static
    {
        return $this->state(fn () => ['show_active_status' => false]);
    }

    public function readReceiptsOff(): static
    {
        return $this->state(fn () => ['read_receipts' => false]);
    }
}
