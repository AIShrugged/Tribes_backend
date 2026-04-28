<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_summaries', function (Blueprint $table) {
            $table->json('repeated_discussions')->nullable()->after('commitments');
        });
    }

    public function down(): void
    {
        Schema::table('meeting_summaries', function (Blueprint $table) {
            $table->dropColumn('repeated_discussions');
        });
    }
};
