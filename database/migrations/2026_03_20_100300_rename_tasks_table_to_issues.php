<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('tasks', 'issues');

        Schema::table('issue_attachments', function (Blueprint $table) {
            if (Schema::hasColumn('issue_attachments', 'task_id')) {
                $table->dropForeign(['task_id']);
            }
        });

        if (Schema::hasColumn('issue_attachments', 'task_id') && ! Schema::hasColumn('issue_attachments', 'issue_id')) {
            Schema::table('issue_attachments', function (Blueprint $table) {
                $table->renameColumn('task_id', 'issue_id');
            });
        }

        Schema::table('issue_attachments', function (Blueprint $table) {
            if (Schema::hasColumn('issue_attachments', 'issue_id')) {
                $table->foreign('issue_id')->references('id')->on('issues')->cascadeOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('issue_attachments', function (Blueprint $table) {
            if (Schema::hasColumn('issue_attachments', 'issue_id')) {
                $table->dropForeign(['issue_id']);
            }
        });

        if (Schema::hasColumn('issue_attachments', 'issue_id') && ! Schema::hasColumn('issue_attachments', 'task_id')) {
            Schema::table('issue_attachments', function (Blueprint $table) {
                $table->renameColumn('issue_id', 'task_id');
            });
        }

        Schema::table('issue_attachments', function (Blueprint $table) {
            if (Schema::hasColumn('issue_attachments', 'task_id')) {
                $table->foreign('task_id')->references('id')->on('tasks')->cascadeOnDelete();
            }
        });

        Schema::rename('issues', 'tasks');
    }
};
