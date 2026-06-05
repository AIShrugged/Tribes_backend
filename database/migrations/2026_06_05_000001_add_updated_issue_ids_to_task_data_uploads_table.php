<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_data_uploads', function (Blueprint $table) {
            // IDs of pre-existing issues this upload merely updated (not created).
            // Created issues are found via the sourceable morph; updated issues have
            // no such link, so we snapshot their ids here to drill through in the log.
            $table->json('updated_issue_ids')->nullable()->after('issues_updated');
        });
    }

    public function down(): void
    {
        Schema::table('task_data_uploads', function (Blueprint $table) {
            $table->dropColumn('updated_issue_ids');
        });
    }
};
