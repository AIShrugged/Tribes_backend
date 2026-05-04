<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('upcoming_agendas', function (Blueprint $table) {
            $table->string('series_key', 1024)->nullable()->after('source_calendar_event_id');
        });

        DB::statement(<<<'SQL'
            UPDATE upcoming_agendas u
            SET series_key = CASE
                WHEN ce.url IS NOT NULL AND ce.url <> '' THEN 'url:' || ce.url
                ELSE 'title:' || COALESCE(ce.title, '')
            END
            FROM calendar_events ce
            WHERE u.source_calendar_event_id = ce.id
        SQL);

        Schema::table('upcoming_agendas', function (Blueprint $table) {
            $table->dropUnique(['user_id']);
            $table->string('series_key', 1024)->nullable(false)->change();
            $table->unique(['user_id', 'series_key']);
        });
    }

    public function down(): void
    {
        Schema::table('upcoming_agendas', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'series_key']);
            $table->unique('user_id');
            $table->dropColumn('series_key');
        });
    }
};
