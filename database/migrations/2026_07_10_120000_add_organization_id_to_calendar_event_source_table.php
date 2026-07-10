<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Track which organization a bot connection was made from, and stop
     * auto-requiring the bot for newly synced calendar events. The bot is now
     * attached manually by the meeting creator from a specific organization,
     * so new pivot rows default to required_bot = false.
     */
    public function up(): void
    {
        Schema::table('calendar_event_source', function (Blueprint $table) {
            $table->foreignId('organization_id')
                ->nullable()
                ->after('source_id')
                ->constrained()
                ->nullOnDelete();

            $table->boolean('required_bot')->default(false)->change();

            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::table('calendar_event_source', function (Blueprint $table) {
            $table->dropIndex(['organization_id']);
            $table->dropForeign(['organization_id']);
            $table->dropColumn('organization_id');

            $table->boolean('required_bot')->default(true)->change();
        });
    }
};
