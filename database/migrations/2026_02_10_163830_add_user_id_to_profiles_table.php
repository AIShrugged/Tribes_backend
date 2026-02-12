<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('email')->constrained()->nullOnDelete();
        });

        // Auto-link existing profiles to users by matching email
        DB::statement('
            UPDATE profiles
            SET user_id = (SELECT id FROM users WHERE users.email = profiles.email)
            WHERE EXISTS (SELECT 1 FROM users WHERE users.email = profiles.email)
        ');
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
