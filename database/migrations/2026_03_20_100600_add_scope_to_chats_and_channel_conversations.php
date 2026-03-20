<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->foreignId('team_id')->nullable()->after('organization_id')->constrained()->nullOnDelete();
            $table->index('organization_id');
            $table->index('team_id');
        });

        Schema::table('channel_conversations', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->foreignId('team_id')->nullable()->after('organization_id')->constrained()->nullOnDelete();
            $table->index('organization_id');
            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::table('channel_conversations', function (Blueprint $table) {
            $table->dropIndex(['organization_id']);
            $table->dropIndex(['team_id']);
            $table->dropConstrainedForeignId('organization_id');
            $table->dropConstrainedForeignId('team_id');
        });

        Schema::table('chats', function (Blueprint $table) {
            $table->dropIndex(['organization_id']);
            $table->dropIndex(['team_id']);
            $table->dropConstrainedForeignId('organization_id');
            $table->dropConstrainedForeignId('team_id');
        });
    }
};
