<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decision_issue', function (Blueprint $table) {
            $table->foreignId('decision_id')->constrained()->cascadeOnDelete();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['decision_id', 'issue_id']);
            $table->index('issue_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decision_issue');
    }
};
