<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unified table for both daily and weekly task digests.
 *
 * `period_type` discriminates daily/weekly.
 * `period_start` is the date the period began (daily: that date; weekly: Monday of that week, server TZ).
 * Unique index on (user_id, organization_id, period_type, period_start) makes updateOrCreate race-safe
 * under redis queue with concurrent retries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_digests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->enum('period_type', ['daily', 'weekly']);
            $table->date('period_start');
            $table->json('content'); // includes schema_version=1, kind, progress, problems, priorities, metrics_snapshot, manager_extras
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->unique(
                ['user_id', 'organization_id', 'period_type', 'period_start'],
                'task_digests_unique_lookup'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_digests');
    }
};
