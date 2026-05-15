<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Postgres ignores ->after(); column is appended. Column ordering is irrelevant
        // for ORM access via cast — no positional reads in code.
        Schema::table('meeting_summaries', function (Blueprint $table): void {
            $table->json('conflicts')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('meeting_summaries', function (Blueprint $table): void {
            $table->dropColumn('conflicts');
        });
    }
};
