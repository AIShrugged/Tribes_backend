<?php

use App\Services\Organization\ProjectCodeBackfiller;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            // Jira-like project prefix (3–10 chars), globally unique, immutable after creation.
            $table->string('code', 10)->nullable()->after('slug');
            // Running per-org issue counter (pcounter): next issue number = last_issue_number + 1.
            $table->unsignedBigInteger('last_issue_number')->default(0)->after('code');
            $table->unique('code');
        });

        Schema::table('issues', function (Blueprint $table) {
            // Per-organization sequential number and the full display code "PREFIX-N".
            $table->unsignedInteger('number')->nullable()->after('id');
            $table->string('code', 32)->nullable()->after('number');
            $table->unique('code');
            $table->index(['organization_id', 'number']);
        });

        // Assign codes to existing organizations, then number their existing issues.
        app(ProjectCodeBackfiller::class)->run();
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropIndex(['organization_id', 'number']);
            $table->dropColumn(['number', 'code']);
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn(['code', 'last_issue_number']);
        });
    }
};
