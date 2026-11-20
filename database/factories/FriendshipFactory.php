<?php

namespace Database\Factories;

use App\Models\Friendship;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class FriendshipFactory extends Factory
{
    protected $model = Friendship::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'friend_id' => User::factory(),
            'pair_key' => 'friendship-'.Str::uuid(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Friendship $friendship) {
            $friendship->forceFill([
                'pair_key' => $friendship->user_id.'-'.$friendship->friend_id,
            ])->save();
        });
    }
}
