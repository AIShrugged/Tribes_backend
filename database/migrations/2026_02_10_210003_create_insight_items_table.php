<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insight_items', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            $table->string('category');
            $table->text('fact');
            $table->decimal('confidence', 3, 2)->default(0.80);
            $table->foreignId('insight_source_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_archived')->default(false);
            $table->timestamps();

            $table->index(['email', 'category', 'is_archived']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insight_items');
    }
};
