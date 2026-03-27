<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Create pivot table
        Schema::create('calendar_event_source', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->string('external_id')->nullable();
            $table->boolean('required_bot')->default(true);
            $table->timestamps();

            $table->unique(['calendar_event_id', 'source_id']);
            $table->index('source_id');
            $table->index('external_id');
        });

        // 2. Add creator_user_id to calendar_events
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->foreignId('creator_user_id')->nullable()->after('source_id')->constrained('users')->nullOnDelete();
        });

        // 3. Migrate existing data to pivot
        DB::statement(<<<'SQL'
            INSERT INTO calendar_event_source (calendar_event_id, source_id, external_id, required_bot, created_at, updated_at)
            SELECT ce.id, ce.source_id, ce.external_id, ce.required_bot, NOW(), NOW()
            FROM calendar_events ce
            WHERE ce.source_id IS NOT NULL
        SQL);

        // 4. Set creator_user_id from source.user_id
        DB::statement(<<<'SQL'
            UPDATE calendar_events
            SET creator_user_id = sources.user_id
            FROM sources
            WHERE calendar_events.source_id = sources.id
        SQL);

        // 5. Merge duplicate CalendarEvents (same url + starts_at)
        $this->mergeDuplicates();

        // 6. Add unique index on (url, starts_at)
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->unique(['url', 'starts_at'], 'calendar_events_url_starts_at_unique');
        });

        // 7. Make source_id nullable
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->bigInteger('source_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropUnique('calendar_events_url_starts_at_unique');
            $table->dropConstrainedForeignId('creator_user_id');
        });

        Schema::dropIfExists('calendar_event_source');
    }

    private function mergeDuplicates(): void
    {
        // Find groups of duplicate events (same url + starts_at)
        $duplicateGroups = DB::select(<<<'SQL'
            SELECT url, starts_at
            FROM calendar_events
            GROUP BY url, starts_at
            HAVING COUNT(*) > 1
        SQL);

        foreach ($duplicateGroups as $group) {
            $events = DB::table('calendar_events')
                ->where('url', $group->url)
                ->where('starts_at', $group->starts_at)
                ->orderByRaw('bot_id IS NOT NULL DESC, id ASC')
                ->get();

            $canonical = $events->first();
            $duplicates = $events->skip(1);

            foreach ($duplicates as $duplicate) {
                $this->mergeEventIntoCanonical($canonical->id, $duplicate->id, $canonical->bot_id, $duplicate->bot_id);
            }
        }
    }

    private function mergeEventIntoCanonical(int $canonicalId, int $duplicateId, ?int $canonicalBotId, ?int $duplicateBotId): void
    {
        // Move pivot rows to canonical event (ignore conflicts)
        DB::statement(<<<'SQL'
            UPDATE calendar_event_source
            SET calendar_event_id = ?
            WHERE calendar_event_id = ?
            AND source_id NOT IN (
                SELECT source_id FROM (
                    SELECT source_id FROM calendar_event_source WHERE calendar_event_id = ?
                ) AS existing
            )
        SQL, [$canonicalId, $duplicateId, $canonicalId]);

        // Delete remaining pivot rows for duplicate
        DB::table('calendar_event_source')->where('calendar_event_id', $duplicateId)->delete();

        // Move calendar_event_profile pivot (ignore existing)
        DB::statement(<<<'SQL'
            UPDATE calendar_event_profile
            SET calendar_event_id = ?
            WHERE calendar_event_id = ?
            AND profile_id NOT IN (
                SELECT profile_id FROM (
                    SELECT profile_id FROM calendar_event_profile WHERE calendar_event_id = ?
                ) AS existing
            )
        SQL, [$canonicalId, $duplicateId, $canonicalId]);
        DB::table('calendar_event_profile')->where('calendar_event_id', $duplicateId)->delete();

        // Move participants if canonical has none
        $canonicalHasParticipants = DB::table('participants')->where('calendar_event_id', $canonicalId)->exists();
        if (!$canonicalHasParticipants) {
            DB::table('participants')->where('calendar_event_id', $duplicateId)->update(['calendar_event_id' => $canonicalId]);
        } else {
            DB::table('participants')->where('calendar_event_id', $duplicateId)->delete();
        }

        // Move transcript entries if canonical has none
        $canonicalHasTranscript = DB::table('transcript_entries')->where('calendar_event_id', $canonicalId)->exists();
        if (!$canonicalHasTranscript) {
            DB::table('transcript_entries')->where('calendar_event_id', $duplicateId)->update(['calendar_event_id' => $canonicalId]);
        } else {
            DB::table('transcript_entries')->where('calendar_event_id', $duplicateId)->delete();
        }

        // Move issues (morphMany) to canonical
        DB::table('issues')
            ->where('sourceable_type', 'App\\Models\\CalendarEvent')
            ->where('sourceable_id', $duplicateId)
            ->update(['sourceable_id' => $canonicalId]);

        // Move meeting_agendas to canonical (delete duplicates)
        DB::table('meeting_agendas')->where('calendar_event_id', $duplicateId)->delete();

        // Delete duplicate's followups and meeting_summaries
        DB::table('followups')->where('calendar_event_id', $duplicateId)->delete();
        DB::table('meeting_summaries')->where('calendar_event_id', $duplicateId)->delete();

        // Copy bot_id if canonical doesn't have one
        if (!$canonicalBotId && $duplicateBotId) {
            DB::table('calendar_events')->where('id', $canonicalId)->update(['bot_id' => $duplicateBotId]);
        }

        // Delete the duplicate event
        DB::table('calendar_events')->where('id', $duplicateId)->delete();
    }
};
