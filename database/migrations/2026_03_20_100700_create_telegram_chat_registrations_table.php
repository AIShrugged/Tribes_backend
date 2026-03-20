<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_chat_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_conversation_id')->unique()->constrained('channel_conversations')->cascadeOnDelete();
            $table->unsignedBigInteger('telegram_chat_id');
            $table->unsignedBigInteger('message_thread_id')->nullable();
            $table->string('chat_type')->nullable();
            $table->string('chat_title')->nullable();
            $table->string('attach_code')->nullable()->unique();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('attach_requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('bound_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('attach_code_issued_at')->nullable();
            $table->timestamp('attach_code_expires_at')->nullable();
            $table->timestamp('attach_code_used_at')->nullable();
            $table->timestamp('bound_at')->nullable();
            $table->timestamps();

            $table->unique(['telegram_chat_id', 'message_thread_id']);
            $table->index(['organization_id', 'team_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_chat_registrations');
    }
};
