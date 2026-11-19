<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Group;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    public function definition(): array
    {
        return [
            'type' => 'direct',
        ];
    }

    public function direct(?string $key = null): static
    {
        return $this->state(fn () => [
            'type' => 'direct',
            'direct_key' => $key ?? fake()->unique()->numerify('direct-####'),
        ]);
    }

    public function group(): static
    {
        return $this->state(fn () => [
            'type' => 'group',
            'direct_key' => null,
            'group_id' => Group::factory(),
        ]);
    }
}
