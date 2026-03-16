<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_chat_messages', function (Blueprint $table) {
            $table->bigInteger('message_thread_id')->nullable()->index()->after('telegram_user_id');
            $table->uuid('agent_batch_uuid')->nullable()->index()->after('content');
            $table->timestamp('coalesced_at')->nullable()->after('agent_batch_uuid');
            $table->timestamp('responded_at')->nullable()->after('coalesced_at');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_chat_messages', function (Blueprint $table) {
            $table->dropColumn(['message_thread_id', 'agent_batch_uuid', 'coalesced_at', 'responded_at']);
        });
    }
};
