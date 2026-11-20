<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\MessageRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class MessageRequestFactory extends Factory
{
    protected $model = MessageRequest::class;

    public function definition(): array
    {
        return [
            'sender_id' => User::factory(),
            'recipient_id' => User::factory(),
            'conversation_id' => Conversation::factory()->direct(),
            'pair_key' => 'request-'.Str::uuid(),
            'body' => fake()->sentence(),
            'status' => 'pending',
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (MessageRequest $request) {
            $ids = [(int) $request->sender_id, (int) $request->recipient_id];
            sort($ids);

            $request->forceFill(['pair_key' => implode('-', $ids)])->save();
        });
    }

    public function accepted(): static
    {
        return $this->state(fn () => [
            'status' => 'accepted',
            'responded_at' => now(),
        ]);
    }

    public function seen(): static
    {
        return $this->state(fn () => ['seen_at' => now()]);
    }

    public function unseen(): static
    {
        return $this->state(fn () => ['seen_at' => null]);
    }
}
