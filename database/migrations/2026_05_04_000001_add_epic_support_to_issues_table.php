<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->foreignId('epic_id')
                ->nullable()
                ->after('issue_type_id')
                ->constrained('issues')
                ->nullOnDelete();
        });

        $now = now();
        DB::table('organization_issue_types')->insert([
            'organization_id' => null,
            'key' => 'epic',
            'name' => 'Epic',
            'base_type' => 'epic',
            'metadata' => null,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->dropConstrainedForeignId('epic_id');
        });

        DB::table('organization_issue_types')
            ->whereNull('organization_id')
            ->where('key', 'epic')
            ->delete();
    }
};
