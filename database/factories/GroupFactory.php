<?php

namespace Database\Factories;

use App\Models\Group;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class GroupFactory extends Factory
{
    protected $model = Group::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'creator_id' => User::factory(),
        ];
    }

    public function withMember(User $user, string $role = 'member'): static
    {
        return $this->afterCreating(function (Group $group) use ($user, $role) {
            $group->members()->syncWithoutDetaching([
                $user->id => ['role' => $role],
            ]);
        });
    }
}
