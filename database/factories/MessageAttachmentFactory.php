<?php

namespace Database\Factories;

use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;

class MessageAttachmentFactory extends Factory
{
    protected $model = MessageAttachment::class;

    public function definition(): array
    {
        return [
            'message_id' => Message::factory(),
            'original_filename' => 'notes.txt',
            'storage_path' => 'conversations/1/files/'.fake()->uuid().'.txt',
            'mime_type' => 'text/plain',
            'file_size' => 1024,
            'attachment_type' => 'file',
        ];
    }

    public function image(): static
    {
        return $this->state(fn () => [
            'original_filename' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
            'attachment_type' => 'image',
        ]);
    }
}
