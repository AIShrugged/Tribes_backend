<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Drop FK on bot_events referencing old bots PK
        Schema::table('bot_events', function (Blueprint $table) {
            $table->dropForeign(['bot_id']);
        });

        // 2. Restructure bots table: add proper id, keep calendar_event_id as regular column
        Schema::table('bots', function (Blueprint $table) {
            $table->dropPrimary();
        });

        Schema::table('bots', function (Blueprint $table) {
            $table->bigIncrements('id')->first();
            $table->string('meeting_url')->nullable()->after('deduplication_key');
        });

        // 3. Populate meeting_url from calendar_events
        DB::statement('
            UPDATE bots SET meeting_url = (
                SELECT url FROM calendar_events WHERE calendar_events.id = bots.calendar_event_id
            )
        ');

        // Make meeting_url not null after populating
        Schema::table('bots', function (Blueprint $table) {
            $table->string('meeting_url')->nullable(false)->change();
        });

        // 4. Add bot_id to calendar_events
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->unsignedBigInteger('bot_id')->nullable()->after('required_bot');
            $table->foreign('bot_id')->references('id')->on('bots')->nullOnDelete();
        });

        // 5. Link existing calendar_events to their bots
        DB::statement('
            UPDATE calendar_events SET bot_id = (
                SELECT bots.id FROM bots WHERE bots.calendar_event_id = calendar_events.id
            )
        ');

        // 6. Update bot_events.bot_id values from old calendar_event_id to new bots.id
        DB::statement('
            UPDATE bot_events SET bot_id = (
                SELECT bots.id FROM bots WHERE bots.calendar_event_id = bot_events.bot_id
            )
        ');

        // 7. Re-add FK on bot_events referencing new bots.id
        Schema::table('bot_events', function (Blueprint $table) {
            $table->foreign('bot_id')->references('id')->on('bots')->cascadeOnDelete();
        });

        // 8. Drop old calendar_event_id from bots (no longer needed)
        Schema::table('bots', function (Blueprint $table) {
            $table->dropColumn('calendar_event_id');
        });
    }

    public function down(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->unsignedBigInteger('calendar_event_id')->nullable();
        });

        DB::statement('
            UPDATE bots SET calendar_event_id = (
                SELECT calendar_events.id FROM calendar_events
                WHERE calendar_events.bot_id = bots.id
                LIMIT 1
            )
        ');

        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropForeign(['bot_id']);
            $table->dropColumn('bot_id');
        });

        Schema::table('bot_events', function (Blueprint $table) {
            $table->dropForeign(['bot_id']);
        });

        Schema::table('bots', function (Blueprint $table) {
            $table->dropColumn(['id', 'meeting_url']);
        });

        Schema::table('bots', function (Blueprint $table) {
            $table->primary('calendar_event_id');
        });

        Schema::table('bot_events', function (Blueprint $table) {
            $table->foreign('bot_id')
                ->references('calendar_event_id')
                ->on('bots')
                ->cascadeOnDelete();
        });
    }
};
