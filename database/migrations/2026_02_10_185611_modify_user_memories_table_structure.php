<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Delete all existing memories (starting fresh with new structure)
        DB::table('user_memories')->delete();

        Schema::table('user_memories', function (Blueprint $table) {
            // Drop unique constraint
            $table->dropUnique(['telegram_user_id', 'key']);

            // Drop columns we no longer need
            $table->dropColumn(['key', 'metadata']);

            // Rename content to text
            $table->renameColumn('content', 'text');

            // Add unique constraint on telegram_user_id (one memory per user)
            $table->unique('telegram_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_memories', function (Blueprint $table) {
            // Remove unique constraint
            $table->dropUnique(['telegram_user_id']);

            // Rename text back to content
            $table->renameColumn('text', 'content');

            // Add back removed columns
            $table->string('key')->after('telegram_user_id');
            $table->json('metadata')->nullable()->after('content');

            // Restore unique constraint
            $table->unique(['telegram_user_id', 'key']);
        });
    }
};
