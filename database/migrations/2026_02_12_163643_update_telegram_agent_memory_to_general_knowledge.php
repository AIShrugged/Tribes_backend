<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Update existing 'telegram_agent_memory' to 'general_knowledge'
        DB::table('insight_short_term')
            ->where('context_type', 'telegram_agent_memory')
            ->update(['context_type' => 'general_knowledge']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert 'general_knowledge' back to 'telegram_agent_memory'
        DB::table('insight_short_term')
            ->where('context_type', 'general_knowledge')
            ->update(['context_type' => 'telegram_agent_memory']);
    }
};
