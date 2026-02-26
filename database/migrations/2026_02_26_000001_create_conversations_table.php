<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('channel_type', 30); // web | telegram_private | telegram_group
            $table->string('external_id', 255)->nullable(); // telegram_chat_id, null for web
            $table->string('title', 500)->nullable();
            $table->string('status', 20)->default('active');
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();
        });

        // Unique index for channel+external_id (only where external_id is not null)
        DB::statement(
            'CREATE UNIQUE INDEX idx_conversations_channel_external
             ON conversations (channel_type, external_id)
             WHERE external_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
