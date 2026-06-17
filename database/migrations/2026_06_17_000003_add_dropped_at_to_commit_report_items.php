<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commit_report_items', function (Blueprint $table) {
            // Set when a review-protected item's sha drops out of the current report on a re-save:
            // the row is preserved (its Pass-2 result kept for audit) but excluded from every read
            // path so a re-bucketed/dropped commit can't linger in the UI or inflate list counts.
            $table->timestamp('dropped_at')->nullable()->after('review_status');
            $table->index(['commit_report_id', 'dropped_at']);
        });
    }

    public function down(): void
    {
        Schema::table('commit_report_items', function (Blueprint $table) {
            $table->dropIndex(['commit_report_id', 'dropped_at']);
            $table->dropColumn('dropped_at');
        });
    }
};
