<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('followups', function (Blueprint $table) {
            // Add new columns
            $table->unsignedBigInteger('team_id')->nullable()->after('calendar_event_id');
            $table->unsignedBigInteger('user_id')->after('team_id');

            // Add indexes for better query performance
            $table->index('team_id');
            $table->index('user_id');

            // Drop old columns
            $table->dropColumn(['participant_id', 'scope']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('followups', function (Blueprint $table) {
            // Restore old columns
            $table->bigInteger('participant_id')->nullable()->after('calendar_event_id');
            $table->enum('scope', ['shared', 'personal'])->after('participant_id');

            // Drop new columns
            $table->dropIndex(['team_id']);
            $table->dropIndex(['user_id']);
            $table->dropColumn(['team_id', 'user_id']);
        });
    }
};