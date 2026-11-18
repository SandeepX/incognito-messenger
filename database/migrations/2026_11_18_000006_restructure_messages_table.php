<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The original demo messages table stored a raw from/to pair. Real chats
 * need messages to belong to a *conversation* so that direct chats and
 * groups share one code path (and one private broadcast channel).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('messages');
        // Left over from an earlier version of the demo.
        Schema::dropIfExists('chat_messages');

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            // Message history is always read newest-first within one thread.
            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('to_user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index(['from_user_id', 'to_user_id']);
            $table->index(['to_user_id', 'from_user_id']);
        });
    }
};
