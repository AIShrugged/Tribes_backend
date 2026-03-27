<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_reviews', function (Blueprint $table) {
            $table->text('trend')->nullable()->after('participation');
            $table->json('previous_suggestions_check')->nullable()->after('trend');
        });
    }

    public function down(): void
    {
        Schema::table('meeting_reviews', function (Blueprint $table) {
            $table->dropColumn(['trend', 'previous_suggestions_check']);
        });
    }
};
