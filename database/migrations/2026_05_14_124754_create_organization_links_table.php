<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('url', 2048);
            $table->timestamps();

            $table->unique(['organization_id', 'url'], 'organization_links_org_url_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_links');
    }
};
