<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_notification_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('minutes_before')->default(60)->after('enabled');
            $table->json('agenda_sections')->nullable()->after('minutes_before');
        });
    }

    public function down(): void
    {
        Schema::table('team_notification_settings', function (Blueprint $table) {
            $table->dropColumn(['minutes_before', 'agenda_sections']);
        });
    }
};
