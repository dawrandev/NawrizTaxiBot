<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_groups', function (Blueprint $table) {
            $table->string('username')->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('bot_groups', function (Blueprint $table) {
            $table->dropColumn('username');
        });
    }
};
