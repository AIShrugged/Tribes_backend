<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_task_runs', function (Blueprint $table) {
            $table->string('paperclip_issue_id')->nullable()->index()->after('run_token_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('agent_task_runs', function (Blueprint $table) {
            $table->dropIndex(['paperclip_issue_id']);
            $table->dropColumn('paperclip_issue_id');
        });
    }
};
