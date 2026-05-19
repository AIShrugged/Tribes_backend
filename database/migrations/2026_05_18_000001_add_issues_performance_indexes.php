<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->index(['assignee_id', 'status', 'close_date'], 'issues_assignee_status_close');
            $table->index(['team_id', 'status', 'due_date'], 'issues_team_status_due');
            $table->index(['organization_id', 'status', 'close_date'], 'issues_org_status_close');
        });
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->dropIndex('issues_assignee_status_close');
            $table->dropIndex('issues_team_status_due');
            $table->dropIndex('issues_org_status_close');
        });
    }
};
