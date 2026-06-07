<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_groups', function (Blueprint $table) {
            $table->boolean('leave_selected')->default(false)->after('wizard_selected');
        });
    }

    public function down(): void
    {
        Schema::table('bot_groups', function (Blueprint $table) {
            $table->dropColumn('leave_selected');
        });
    }
};
