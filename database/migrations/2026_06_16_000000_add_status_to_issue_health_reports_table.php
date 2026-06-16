<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issue_health_reports', function (Blueprint $table) {
            $table->string('status')->default('done')->after('organization_id');
        });
    }

    public function down(): void
    {
        Schema::table('issue_health_reports', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
