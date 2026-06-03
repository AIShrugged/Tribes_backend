<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // minutes_before is "null = use the consumer's default" (agenda → 60, pre-brief → 15).
        // It was NOT NULL default(60), so clearing the lead time (PUT set-minutes-before with null)
        // threw a 23502 violation. Make it nullable to honour the read-side `?? default` contract.
        Schema::table('team_notification_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('minutes_before')->nullable()->default(60)->change();
        });
    }

    public function down(): void
    {
        Schema::table('team_notification_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('minutes_before')->default(60)->change();
        });
    }
};
