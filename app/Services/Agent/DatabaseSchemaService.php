<?php

namespace App\Services\Agent;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DatabaseSchemaService
{
    private const CACHE_KEY = 'agent_db_schema';

    private const CACHE_TTL = 3600; // 1 hour

    /**
     * Returns a formatted schema string for injection into the agent's system prompt.
     * Excludes blacklisted columns so the LLM never learns they exist.
     */
    public function getSchemaForAgent(): string
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn () => $this->buildSchema());
    }

    /**
     * Invalidate the cached schema (call after migrations).
     */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function buildSchema(): string
    {
        $allowedTables = config('agent.sql_allowed_tables', []);
        $blacklist      = config('agent.sql_column_blacklist', []);

        $lines = [];

        foreach ($allowedTables as $table) {
            $columns = DB::select(
                "SELECT column_name, data_type
                 FROM information_schema.columns
                 WHERE table_schema = 'public' AND table_name = ?
                 ORDER BY ordinal_position",
                [$table]
            );

            if (empty($columns)) {
                // Table not yet migrated or doesn't exist — skip silently
                continue;
            }

            $tableBlacklist = array_map('strtolower', $blacklist[$table] ?? []);

            $visible = array_filter(
                $columns,
                fn ($col) => ! in_array(strtolower($col->column_name), $tableBlacklist, true)
            );

            $colList = implode(', ', array_map(fn ($c) => $c->column_name, $visible));
            $lines[] = "  {$table}: {$colList}";
        }

        return implode("\n", $lines);
    }
}
