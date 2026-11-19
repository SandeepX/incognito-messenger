<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('callee_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();

            // ringing | active | ended | declined | missed | busy
            $table->string('status', 20)->default('ringing');
            $table->string('end_reason', 30)->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('ringing_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();

            // Which browser tab owns each leg. Other tabs of the same user
            // ring, but only these tabs exchange SDP/ICE.
            $table->string('caller_tab_id', 64)->nullable();
            $table->string('callee_tab_id', 64)->nullable();

            $table->timestamps();

            $table->index(['caller_id', 'status']);
            $table->index(['callee_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calls');
    }
};
