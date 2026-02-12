<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insight_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            $table->string('category');
            $table->jsonb('content')->default('{}');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('source_count')->default(0);
            $table->timestamp('last_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['email', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insight_profiles');
    }
};
