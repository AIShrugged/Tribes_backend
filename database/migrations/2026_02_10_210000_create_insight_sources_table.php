<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insight_sources', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            $table->string('source_type')->default('transcript');
            $table->unsignedBigInteger('source_id');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['email', 'source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insight_sources');
    }
};
