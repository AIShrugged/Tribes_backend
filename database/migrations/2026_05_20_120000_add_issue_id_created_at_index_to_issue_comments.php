<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Index required by withMax('comments as latest_comment_at', 'created_at')
 * in StuckIssueNudgeService::findCandidates(). Without it the daily nudge cron
 * performs a sequential scan of issue_comments per Issue row.
 *
 * Applied CONCURRENTLY to avoid locking issue_comments during deploy.
 * Migration disables its own transaction wrapper — PG requires CONCURRENTLY
 * outside of transactions.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS issue_comments_issue_id_created_at_index ON issue_comments (issue_id, created_at)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS issue_comments_issue_id_created_at_index');
    }
};
