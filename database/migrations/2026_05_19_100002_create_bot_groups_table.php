<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_bot_id')->constrained()->cascadeOnDelete();
            $table->string('group_chat_id', 50);
            $table->string('title')->default('');
            $table->boolean('run_selected')->default(false);
            $table->boolean('wizard_selected')->default(false);
            $table->timestamps();

            $table->unique(['driver_bot_id', 'group_chat_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_groups');
    }
};
