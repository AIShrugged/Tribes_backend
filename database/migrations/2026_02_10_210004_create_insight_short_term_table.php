<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insight_short_term', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            $table->string('context_type');
            $table->jsonb('content')->default('{}');
            $table->foreignId('insight_source_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['email', 'context_type', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insight_short_term');
    }
};
