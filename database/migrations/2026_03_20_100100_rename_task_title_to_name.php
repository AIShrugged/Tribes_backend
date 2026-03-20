<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('tasks', 'name')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->dropColumn('name');
            });
        }

        Schema::table('tasks', function (Blueprint $table) {
            $table->renameColumn('title', 'name');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->renameColumn('name', 'title');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->string('name')->nullable()->after('status');
        });
    }
};
