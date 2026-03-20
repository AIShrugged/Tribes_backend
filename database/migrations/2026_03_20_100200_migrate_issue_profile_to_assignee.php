<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            UPDATE tasks
            SET assignee_id = profiles.user_id
            FROM profiles
            WHERE tasks.profile_id = profiles.id
              AND profiles.user_id IS NOT NULL
              AND tasks.assignee_id IS NULL
        ');

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('profile_id');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('profile_id')->nullable()->after('taskable_id')->constrained()->nullOnDelete();
        });

        DB::statement('
            UPDATE tasks
            SET profile_id = profiles.id
            FROM profiles
            WHERE tasks.assignee_id = profiles.user_id
              AND tasks.profile_id IS NULL
        ');
    }
};
