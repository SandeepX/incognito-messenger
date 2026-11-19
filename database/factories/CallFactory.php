<?php

namespace Database\Factories;

use App\Models\Call;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Call>
 */
class CallFactory extends Factory
{
    protected $model = Call::class;

    public function definition(): array
    {
        return [
            'caller_id' => User::factory(),
            'callee_id' => User::factory(),
            'conversation_id' => null,
            'status' => 'ringing',
            'caller_tab_id' => 'caller-tab',
        ];
    }

    public function forConversation(Conversation $conversation): static
    {
        return $this->state(fn () => ['conversation_id' => $conversation->id]);
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => 'active',
            'answered_at' => now(),
        ]);
    }

    public function completed(int $seconds = 65): static
    {
        return $this->state(fn () => [
            'status' => 'ended',
            'end_reason' => 'hangup',
            'answered_at' => now()->subSeconds($seconds),
            'ended_at' => now(),
            'duration_seconds' => $seconds,
        ]);
    }

    public function missed(): static
    {
        return $this->state(fn () => [
            'status' => 'missed',
            'end_reason' => 'timeout',
            'ended_at' => now(),
        ]);
    }

    public function declined(): static
    {
        return $this->state(fn () => [
            'status' => 'declined',
            'end_reason' => 'declined',
            'ended_at' => now(),
        ]);
    }
}
