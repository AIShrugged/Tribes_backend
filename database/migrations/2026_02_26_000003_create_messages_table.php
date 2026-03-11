<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20); // user | assistant
            $table->text('content');
            $table->string('sender_type', 100)->nullable(); // App\Models\User | App\Models\TelegramUser
            $table->unsignedBigInteger('sender_id')->nullable();
            $table->string('source_msg_id', 100)->nullable(); // Telegram message_id for dedup
            $table->jsonb('metadata')->default('{}'); // followup_data и прочее
            $table->timestamps();

            // Основной запрос: история чата от новых к старым
            $table->index(['conversation_id', 'created_at'], 'idx_messages_conv_created');
        });

        // Partial index для дедупликации Telegram сообщений
        DB::statement(
            'CREATE INDEX idx_messages_source_msg_id
             ON messages (conversation_id, source_msg_id)
             WHERE source_msg_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
