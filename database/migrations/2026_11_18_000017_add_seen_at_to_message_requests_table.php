<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_requests', function (Blueprint $table) {
            $table->timestamp('seen_at')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('message_requests', function (Blueprint $table) {
            $table->dropColumn('seen_at');
        });
    }
};
