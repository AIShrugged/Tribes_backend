<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('invites', function (Blueprint $table) {
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('team_id')->constrained()->onDelete('cascade');
            $table->foreignId('invited_by_id')->constrained('users')->onDelete('cascade');
            $table->string('email');
            $table->string('token');
            $table->enum('status', ['pending', 'accepted', 'cancelled', 'expired'])->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();

            $table->index(['token', 'status']);
            $table->index(['team_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invites', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropForeign(['team_id']);
            $table->dropForeign(['invited_by_id']);
            $table->dropIndex(['token', 'status']);
            $table->dropIndex(['team_id', 'status']);
            $table->dropColumn([
                'organization_id',
                'team_id',
                'invited_by_id',
                'email',
                'token',
                'status',
                'expires_at',
                'accepted_at',
            ]);
        });
    }
};
