<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issue_comments', function (Blueprint $table) {
            $table->foreignId('calendar_event_id')->nullable()->after('parent_id')->constrained('calendar_events')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('issue_comments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('calendar_event_id');
        });
    }
};
