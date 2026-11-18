<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->string('theme_preference', 10)->default('system')->after('message_sounds');
            $table->unsignedTinyInteger('message_sounds_volume')->default(100)->after('theme_preference');
            $table->boolean('desktop_notifications')->default(false)->after('message_sounds_volume');
            $table->boolean('read_receipts')->default(true)->after('desktop_notifications');
            $table->string('message_request_policy', 20)->default('everyone')->after('read_receipts');
            $table->string('notes_visibility', 10)->default('friends')->after('message_request_policy');
        });
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'theme_preference',
                'message_sounds_volume',
                'desktop_notifications',
                'read_receipts',
                'message_request_policy',
                'notes_visibility',
            ]);
        });
    }
};
