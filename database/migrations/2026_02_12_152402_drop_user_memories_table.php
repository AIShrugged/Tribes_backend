<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('user_memories');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Cannot rollback - table structure is complex and no longer needed
        // Original migrations: 2026_02_10_163454 and 2026_02_10_185611
    }
};
