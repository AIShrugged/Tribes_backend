<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_tasks', function (Blueprint $table) {
            $table->foreignId('parent_agent_task_id')->nullable()->after('user_id')->constrained('agent_tasks')->nullOnDelete();
            $table->foreignId('origin_agent_task_run_id')->nullable()->after('parent_agent_task_id')->constrained('agent_task_runs')->nullOnDelete();
            $table->unsignedSmallInteger('followup_depth')->default(0)->after('origin_agent_task_run_id');

            $table->index(['parent_agent_task_id', 'created_at']);
            $table->index(['origin_agent_task_run_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('agent_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_agent_task_id');
            $table->dropConstrainedForeignId('origin_agent_task_run_id');
            $table->dropColumn('followup_depth');
        });
    }
};
