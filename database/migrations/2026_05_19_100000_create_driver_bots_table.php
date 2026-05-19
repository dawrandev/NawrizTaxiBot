<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_bots', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('chat_id', 50);
            $table->string('bot_token')->unique();
            $table->string('bot_username', 100)->nullable();
            $table->boolean('is_active')->default(false);
            // current_template_id: plain integer, no FK (circular dependency with templates)
            $table->unsignedBigInteger('current_template_id')->nullable();
            $table->unsignedBigInteger('wizard_template_id')->nullable();
            $table->integer('interval')->default(30);
            $table->integer('wizard_interval')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->string('pending', 100)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_bots');
    }
};
