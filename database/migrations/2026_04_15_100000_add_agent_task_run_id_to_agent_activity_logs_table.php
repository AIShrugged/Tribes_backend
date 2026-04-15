<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_activity_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('agent_task_run_id')->nullable()->after('agent_run_uuid');
            $table->index('agent_task_run_id');
        });
    }

    public function down(): void
    {
        Schema::table('agent_activity_logs', function (Blueprint $table) {
            $table->dropIndex(['agent_task_run_id']);
            $table->dropColumn('agent_task_run_id');
        });
    }
};
