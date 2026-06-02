<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_data_uploads', function (Blueprint $table) {
            $table->text('error_message')->nullable()->after('issues_updated');
        });
    }

    public function down(): void
    {
        Schema::table('task_data_uploads', function (Blueprint $table) {
            $table->dropColumn('error_message');
        });
    }
};
