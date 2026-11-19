<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('type', 20)->default('text')->after('body');
            $table->foreignId('call_id')->nullable()->after('type')
                ->constrained('calls')->nullOnDelete();
            $table->unique('call_id');
        });

        Schema::table('user_profiles', function (Blueprint $table) {
            $table->string('allow_calls', 20)->default('friends')->after('notes_visibility');
            $table->boolean('call_ringtone')->default(true)->after('allow_calls');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique(['call_id']);
            $table->dropConstrainedForeignId('call_id');
            $table->dropColumn('type');
        });

        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropColumn(['allow_calls', 'call_ringtone']);
        });
    }
};
