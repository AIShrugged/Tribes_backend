<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The unified Upload Log runs scoped WHERE + `created_at DESC` on both tables
        // on every open. task_data_uploads currently has zero indexes.
        Schema::table('transcript_uploads', function (Blueprint $table) {
            $table->index(['organization_id', 'created_at'], 'transcript_uploads_org_created');
            $table->index(['user_id', 'created_at'], 'transcript_uploads_user_created');
        });

        Schema::table('task_data_uploads', function (Blueprint $table) {
            $table->index(['organization_id', 'created_at'], 'task_data_uploads_org_created');
            $table->index(['team_id', 'created_at'], 'task_data_uploads_team_created');
            $table->index(['user_id', 'created_at'], 'task_data_uploads_user_created');
        });
    }

    public function down(): void
    {
        Schema::table('transcript_uploads', function (Blueprint $table) {
            $table->dropIndex('transcript_uploads_org_created');
            $table->dropIndex('transcript_uploads_user_created');
        });

        Schema::table('task_data_uploads', function (Blueprint $table) {
            $table->dropIndex('task_data_uploads_org_created');
            $table->dropIndex('task_data_uploads_team_created');
            $table->dropIndex('task_data_uploads_user_created');
        });
    }
};
