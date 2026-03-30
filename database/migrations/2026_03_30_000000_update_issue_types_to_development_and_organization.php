<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE issues
            SET type = CASE
                WHEN type = 'task' THEN 'organization'
                WHEN type = 'bug' THEN 'development'
                ELSE type
            END
        SQL);

        DB::statement("ALTER TABLE issues ALTER COLUMN type SET DEFAULT 'development'");
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            UPDATE issues
            SET type = CASE
                WHEN type = 'development' THEN 'bug'
                WHEN type = 'organization' THEN 'task'
                ELSE type
            END
        SQL);

        DB::statement("ALTER TABLE issues ALTER COLUMN type SET DEFAULT 'task'");
    }
};
