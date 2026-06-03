<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('critical_path_graphs', function (Blueprint $table): void {
            // Hash of the last critical-path state we notified about (sorted critical
            // issue ids + project duration). Lets notifyTeam debounce: skip sending a
            // "critical path updated" message when the critical path did not actually change.
            $table->string('last_notified_signature')->nullable()->after('computed_at');
        });
    }

    public function down(): void
    {
        Schema::table('critical_path_graphs', function (Blueprint $table): void {
            $table->dropColumn('last_notified_signature');
        });
    }
};
