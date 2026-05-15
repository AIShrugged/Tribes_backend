<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_summary_templates', function (Blueprint $table) {
            $table->text('prompt_override')->nullable()->after('sections');
            $table->unsignedInteger('version')->default(1)->after('prompt_override');
        });
    }

    public function down(): void
    {
        Schema::table('meeting_summary_templates', function (Blueprint $table) {
            $table->dropColumn(['prompt_override', 'version']);
        });
    }
};
