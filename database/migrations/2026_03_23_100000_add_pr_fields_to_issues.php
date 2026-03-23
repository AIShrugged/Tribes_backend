<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->string('pr_url')->nullable()->after('status');
            $table->integer('pr_number')->nullable()->after('pr_url');
            $table->string('pr_repository')->nullable()->after('pr_number');
            $table->unsignedBigInteger('agent_task_id')->nullable()->after('pr_repository');

            $table->foreign('agent_task_id')
                ->references('id')
                ->on('agent_tasks')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->dropForeign(['agent_task_id']);
            $table->dropColumn(['pr_url', 'pr_number', 'pr_repository', 'agent_task_id']);
        });
    }
};
