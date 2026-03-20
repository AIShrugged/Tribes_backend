<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('name')->nullable()->after('status');
            $table->string('type')->default('task')->after('name');
            $table->foreignId('assignee_id')->nullable()->after('type')->constrained('users')->nullOnDelete();
            $table->dateTime('registration_date')->nullable()->after('assignee_id');
            $table->dateTime('close_date')->nullable()->after('registration_date');
            $table->softDeletes()->after('updated_at');

            $table->index('status');
            $table->index('type');
            $table->index('assignee_id');
            $table->index('registration_date');
        });

        Schema::create('issue_attachments', function (Blueprint $table) {
            $table->id();
            $table->string('file_path');
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->timestamp('uploaded_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_attachments');

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['type']);
            $table->dropIndex(['assignee_id']);
            $table->dropIndex(['registration_date']);

            $table->dropConstrainedForeignId('user_id');
            $table->dropConstrainedForeignId('assignee_id');
            $table->dropSoftDeletes();
            $table->dropColumn([
                'name',
                'type',
                'registration_date',
                'close_date',
            ]);
        });
    }
};
