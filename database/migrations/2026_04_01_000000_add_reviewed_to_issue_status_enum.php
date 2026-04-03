<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migration: add_reviewed_to_issue_status_enum
 *
 * Adds the 'reviewed' status value to the IssueStatus enum used in the issues table.
 *
 * IMPORTANT — Storage note:
 * The `issues.status` column is stored as a plain VARCHAR string (not a native
 * PostgreSQL ENUM type). No CREATE TYPE ... AS ENUM was ever used; all previous
 * migrations used $table->string('status').
 *
 * Therefore, there is no native PostgreSQL enum type to ALTER. The "enum" is
 * enforced entirely at the application layer (MeetingTaskStatus PHP enum +
 * IssueRequest validation).
 *
 * If the project were using a native PostgreSQL enum type (e.g., issue_status),
 * the up() method would execute:
 *
 *   -- PostgreSQL ≥ 14 supports BEFORE/AFTER positioning:
 *   ALTER TYPE issue_status ADD VALUE IF NOT EXISTS 'reviewed' BEFORE 'in_progress';
 *
 *   -- PostgreSQL < 14 — no BEFORE/AFTER support; value appended at the end:
 *   ALTER TYPE issue_status ADD VALUE IF NOT EXISTS 'reviewed';
 *
 * Since no native type exists, this migration is intentionally a no-op at the
 * database level. The corresponding application-layer changes (MeetingTaskStatus
 * enum + IssueRequest::VALID_STATUSES) are handled in separate steps of issue #200.
 *
 * @see app/Enums/MeetingTaskStatus.php
 * @see app/Http/Requests/API/v1/IssueRequest.php
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds 'reviewed' to the IssueStatus set.
     *
     * Because `issues.status` is a plain VARCHAR column, no DDL is required.
     * The comment below shows what the statement would look like if a native
     * PostgreSQL ENUM type were in use.
     *
     * PostgreSQL ≥ 14 supports BEFORE/AFTER positioning, so 'reviewed' would be
     * inserted before 'in_progress' to match the intended workflow position:
     *
     *     open → reviewed → in_progress → paused → reopen → done
     *
     * Native ENUM equivalent (not executed — column is VARCHAR):
     *
     *     ALTER TYPE issue_status ADD VALUE IF NOT EXISTS 'reviewed' BEFORE 'in_progress';
     *
     * For PostgreSQL < 14 (no BEFORE/AFTER support), the value would be appended:
     *
     *     ALTER TYPE issue_status ADD VALUE IF NOT EXISTS 'reviewed';
     *
     * In that case the physical order of enum values would differ from the logical
     * workflow order, but SELECT/comparison semantics would be unaffected for a
     * VARCHAR column.
     */
    public function up(): void
    {
        // No DDL required: `issues.status` is a VARCHAR column, not a native
        // PostgreSQL ENUM type. The 'reviewed' value is enforced at the
        // application layer via MeetingTaskStatus PHP enum and IssueRequest
        // validation rules.
        //
        // If a native ENUM type existed, the statement would be:
        //
        //   DB::statement("ALTER TYPE issue_status ADD VALUE IF NOT EXISTS 'reviewed' BEFORE 'in_progress'");
        //
        // (BEFORE/AFTER requires PostgreSQL ≥ 14. For older versions, omit the
        //  positioning clause and the value will be appended at the end of the enum.)
    }

    /**
     * Reverse the migrations.
     *
     * PostgreSQL does NOT support removing values from an enum type once they
     * have been added. Rolling back this migration would require:
     *   1. Creating a new enum type without 'reviewed'.
     *   2. Updating all columns that reference the old type.
     *   3. Dropping the old type and renaming the new one.
     *
     * This is a destructive, multi-step operation that cannot be performed
     * automatically without risk of data loss (any row with status = 'reviewed'
     * would need to be migrated to another status first).
     *
     * Rollback is therefore not supported and this method throws an exception
     * to prevent accidental down-migration.
     */
    public function down(): void
    {
        // PostgreSQL does not support removing a value from an enum type after
        // it has been added (ALTER TYPE ... DROP VALUE does not exist).
        // Rolling back requires recreating the type from scratch, which is a
        // destructive operation that may cause data loss for any rows that
        // already use the 'reviewed' status.
        //
        // To perform a rollback manually, you would need to:
        //   1. Migrate all issues with status = 'reviewed' to another status.
        //   2. Create a replacement type without 'reviewed'.
        //   3. Alter all affected columns to use the new type.
        //   4. Drop the old type.
        //
        // This migration does NOT perform these steps automatically.
        throw new \RuntimeException(
            "Cannot roll back migration 'add_reviewed_to_issue_status_enum': " .
            "PostgreSQL does not support removing values from an enum type without " .
            "recreating the type entirely. Manual intervention is required: migrate " .
            "existing rows away from status='reviewed', then recreate the type."
        );
    }
};