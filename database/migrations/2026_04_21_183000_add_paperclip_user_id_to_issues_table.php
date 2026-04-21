<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table): void {
            if (! Schema::hasColumn('issues', 'paperclip_user_id')) {
                $table->foreignId('paperclip_user_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table): void {
            if (Schema::hasColumn('issues', 'paperclip_user_id')) {
                $table->dropConstrainedForeignId('paperclip_user_id');
            }
        });
    }
};
