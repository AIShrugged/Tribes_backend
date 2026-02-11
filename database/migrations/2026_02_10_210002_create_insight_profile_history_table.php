<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insight_profile_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('insight_profile_id')->constrained()->cascadeOnDelete();
            $table->string('email')->index();
            $table->string('category');
            $table->jsonb('content');
            $table->unsignedInteger('version');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insight_profile_history');
    }
};
