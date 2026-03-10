<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('participants', function (Blueprint $table) {
            $table->unsignedTinyInteger('profile_confidence')->nullable()->after('profile_id');
            $table->string('profile_matched_by')->nullable()->after('profile_confidence'); // 'ai' | 'manual'
        });
    }

    public function down(): void
    {
        Schema::table('participants', function (Blueprint $table) {
            $table->dropColumn(['profile_confidence', 'profile_matched_by']);
        });
    }
};
