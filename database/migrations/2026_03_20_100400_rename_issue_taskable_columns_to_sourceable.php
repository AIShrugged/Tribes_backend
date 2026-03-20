<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->renameColumn('taskable_type', 'sourceable_type');
            $table->renameColumn('taskable_id', 'sourceable_id');
        });
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->renameColumn('sourceable_type', 'taskable_type');
            $table->renameColumn('sourceable_id', 'taskable_id');
        });
    }
};
