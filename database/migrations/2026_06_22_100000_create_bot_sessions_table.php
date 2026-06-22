<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_bot_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('stopped_at')->nullable();
            $table->timestamps();

            $table->index(['driver_bot_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_sessions');
    }
};
