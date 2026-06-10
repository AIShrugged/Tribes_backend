<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issue_health_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->json('findings');
            $table->text('ai_summary')->nullable();
            $table->timestamp('generated_at');
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->unique(
                ['team_id', 'organization_id', 'period_start'],
                'issue_health_reports_unique_lookup',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_health_reports');
    }
};
