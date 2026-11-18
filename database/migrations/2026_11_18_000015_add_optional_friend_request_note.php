<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('friend_requests', function (Blueprint $table) {
            $table->string('note', 160)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('friend_requests', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
