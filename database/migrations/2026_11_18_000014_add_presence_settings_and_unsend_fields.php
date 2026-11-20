<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_active_at')->nullable()->after('remember_token');
            $table->index('last_active_at');
        });

        Schema::table('user_profiles', function (Blueprint $table) {
            $table->boolean('show_active_status')->default(true)->after('status');
            $table->boolean('message_sounds')->default(true)->after('show_active_status');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->boolean('is_unsent')->default(false)->after('body');
            $table->timestamp('unsent_at')->nullable()->after('is_unsent');
            $table->foreignId('unsent_by')->nullable()->after('unsent_at')
                ->constrained('users')->nullOnDelete();
            $table->json('deleted_for')->nullable()->after('unsent_by');
            $table->index('is_unsent');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['is_unsent']);
            $table->dropColumn(['is_unsent', 'unsent_at', 'unsent_by', 'deleted_for']);
        });

        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropColumn(['show_active_status', 'message_sounds']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['last_active_at']);
            $table->dropColumn('last_active_at');
        });
    }
};
