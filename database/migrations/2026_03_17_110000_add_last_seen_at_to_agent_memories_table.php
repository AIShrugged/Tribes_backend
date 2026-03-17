<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_memories', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('agent_memories', function (Blueprint $table) {
            $table->dropColumn('last_seen_at');
        });
    }
};
