<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pending validation question records that pair an outgoing Telegram message
 * (the validator's questions) with the IssueAgentFlow waiting on the author's
 * answer. When the user replies in Telegram to that message, the webhook
 * matches (telegram_chat_id, telegram_message_id) and feeds the reply into
 * IssueAgentFlowService::answer().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issue_agent_flow_pending_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issue_agent_flow_id')->constrained('issue_agent_flows')->cascadeOnDelete();
            $table->foreignId('issue_id')->constrained('issues')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('telegram_chat_id')->nullable();
            $table->unsignedBigInteger('telegram_message_id')->nullable();
            $table->json('questions')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['telegram_chat_id', 'telegram_message_id'], 'iafpr_tg_match_idx');
            $table->index(['user_id', 'consumed_at'], 'iafpr_user_active_idx');
            $table->index('expires_at', 'iafpr_expires_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_agent_flow_pending_replies');
    }
};
