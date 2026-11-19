<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageRead;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    protected $model = Message::class;

    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'sender_id' => User::factory(),
            'body' => fake()->sentence(),
            'type' => 'text',
            'is_unsent' => false,
        ];
    }

    /** A normal message with no recipient read receipt. */
    public function unseen(): static
    {
        return $this->state(fn () => [
            'is_unsent' => false,
            'deleted_for' => null,
        ]);
    }

    /** The recipient already read this message (a real message_reads row). */
    public function seen(): static
    {
        return $this->afterCreating(function (Message $message) {
            $message->conversation->participants()
                ->whereKeyNot($message->sender_id)
                ->pluck('users.id')
                ->each(fn ($userId) => MessageRead::firstOrCreate([
                    'message_id' => $message->id,
                    'user_id' => (int) $userId,
                ], ['read_at' => now()]));
        });
    }

    /** Sender detached the message for everyone; body and media are cleared. */
    public function unsent(): static
    {
        return $this->state(fn () => [
            'body' => '',
            'is_unsent' => true,
            'unsent_at' => now(),
        ])->afterCreating(function (Message $message) {
            $message->forceFill(['unsent_by' => $message->sender_id])->save();
        });
    }
}
