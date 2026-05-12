<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('participants', function (Blueprint $table) {
            $table->index('calendar_event_id');
            $table->index('profile_id');
        });

        Schema::table('calendar_events', function (Blueprint $table) {
            $table->index('starts_at');
        });

        Schema::table('telegram_chat_messages', function (Blueprint $table) {
            $table->index(['telegram_chat_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('participants', function (Blueprint $table) {
            $table->dropIndex(['calendar_event_id']);
            $table->dropIndex(['profile_id']);
        });

        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropIndex(['starts_at']);
        });

        Schema::table('telegram_chat_messages', function (Blueprint $table) {
            $table->dropIndex(['telegram_chat_id', 'created_at']);
        });
    }
};
