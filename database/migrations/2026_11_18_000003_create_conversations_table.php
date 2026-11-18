<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10); // direct | group
            // Set for group conversations; null for 1:1 chats.
            $table->foreignId('group_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            // Sorted pair of user ids, e.g. "1-2" — prevents duplicate DMs.
            $table->string('direct_key', 30)->nullable()->unique();
            $table->timestamps();

            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
