<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill organization_id on sources for users who belong to exactly one organization.
     *
     * This preserves MeetingSeriesState hashes for existing data: the new
     * buildSeriesIdentifier() uses source->organization_id directly, so a source
     * with organization_id=42 produces the same hash as the old user→organizations
     * join when the user is in exactly one org.
     *
     * Multi-org users are intentionally skipped: the old hash included all their
     * org IDs (e.g. "17,42"), which can't be collapsed to a single value without
     * data loss, so their series states reset on first new meeting.
     */
    public function up(): void
    {
        DB::statement("
            UPDATE sources
            SET organization_id = (
                SELECT organization_id
                FROM organization_user
                WHERE organization_user.user_id = sources.user_id
                LIMIT 1
            )
            WHERE sources.organization_id IS NULL
              AND sources.deleted_at IS NULL
              AND (
                SELECT COUNT(DISTINCT organization_id)
                FROM organization_user
                WHERE organization_user.user_id = sources.user_id
              ) = 1
        ");
    }

    public function down(): void
    {
        // Irreversible: we can't know which organization_id values were backfilled
        // vs. set intentionally. Safe to leave as-is on rollback.
    }
};
