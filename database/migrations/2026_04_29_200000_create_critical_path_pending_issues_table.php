<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('critical_path_pending_issues', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->foreignId('issue_id')->constrained('issues')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['organization_id', 'issue_id']);
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('critical_path_pending_issues');
    }
};
