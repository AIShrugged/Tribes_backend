<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track RSVP / attendance status per attached profile.
 * Populated by Google Calendar sync; consumed by AgendaRenderer to split
 * agenda recipients into 👥 attending / ⚠️ not attending blocks.
 *
 * Allowed values follow Google Calendar API attendee.responseStatus:
 *   accepted | declined | tentative | needs_action
 *
 * Stored as varchar (no DB-level enum constraint) so future GCal additions
 * don't require a migration. Rendering treats anything other than 'declined'
 * as attending.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_event_profile', function (Blueprint $table) {
            $table->string('response_status', 32)->nullable()->after('profile_id');
        });
    }

    public function down(): void
    {
        Schema::table('calendar_event_profile', function (Blueprint $table) {
            $table->dropColumn('response_status');
        });
    }
};
