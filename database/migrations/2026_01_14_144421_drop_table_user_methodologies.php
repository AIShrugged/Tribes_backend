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
        Schema::dropIfExists('user_methodologies');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('user_methodologies', function (Blueprint $table) {
            $table->id('user_id');
            $table->bigInteger('methodology_id')->index();
        });
    }
};
