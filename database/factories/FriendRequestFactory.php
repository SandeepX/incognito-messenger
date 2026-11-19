<?php

namespace Database\Factories;

use App\Models\FriendRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FriendRequest>
 */
class FriendRequestFactory extends Factory
{
    protected $model = FriendRequest::class;

    public function definition(): array
    {
        return [
            'sender_id' => User::factory(),
            'recipient_id' => User::factory(),
            'pair_key' => 'pending-'.Str::uuid(),
            'status' => 'pending',
            'note' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (FriendRequest $request) {
            $ids = [(int) $request->sender_id, (int) $request->recipient_id];
            sort($ids);

            $request->forceFill(['pair_key' => implode('-', $ids)])->save();
        });
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => 'pending',
            'responded_at' => null,
            'seen_at' => null,
        ]);
    }

    public function seen(): static
    {
        return $this->state(fn () => [
            'status' => 'pending',
            'seen_at' => now(),
        ]);
    }

    public function unseen(): static
    {
        return $this->state(fn () => [
            'status' => 'pending',
            'seen_at' => null,
        ]);
    }

    public function accepted(): static
    {
        return $this->state(fn () => [
            'status' => 'accepted',
            'responded_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'status' => 'rejected',
            'responded_at' => now(),
        ]);
    }
}
