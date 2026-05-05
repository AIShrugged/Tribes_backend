<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issue_attachments', function (Blueprint $table) {
            $table->unsignedBigInteger('issue_id')->nullable()->change();
            $table->string('upload_token', 64)->nullable()->after('issue_id');
            $table->foreignId('uploaded_by_user_id')
                ->nullable()
                ->after('upload_token')
                ->constrained('users')
                ->nullOnDelete();

            $table->index(['upload_token', 'uploaded_by_user_id'], 'ia_token_user_idx');
            $table->index(['issue_id', 'uploaded_at'], 'ia_issue_uploaded_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('issue_attachments', function (Blueprint $table) {
            $table->dropIndex('ia_token_user_idx');
            $table->dropIndex('ia_issue_uploaded_at_idx');
            $table->dropConstrainedForeignId('uploaded_by_user_id');
            $table->dropColumn('upload_token');
        });
    }
};