<?php

namespace Tests\Feature\Agent;

use App\Services\Chat\SqlQueryExecutor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Stage 0 hardening: raw SQL (when enabled on an opt-in autonomous run) must not be
 * able to read PII / psychological insight tables — they were removed from the
 * whitelist. Validation rejects them before any DB access.
 */
class SqlQueryExecutorScopingTest extends TestCase
{
    #[Test]
    public function raw_sql_cannot_read_psychological_insight_tables(): void
    {
        $executor = new SqlQueryExecutor;

        foreach (['insight_profiles', 'insight_items', 'insight_relationships'] as $table) {
            $result = $executor->execute("SELECT * FROM {$table}", [1]);

            $this->assertFalse($result->success, "Query on {$table} must be rejected");
            $this->assertStringContainsStringIgnoringCase('not allowed', $result->error ?? '');
        }
    }
}
