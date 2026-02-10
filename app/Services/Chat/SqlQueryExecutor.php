<?php

namespace App\Services\Chat;

use App\Exceptions\SqlAccessControlException;
use App\Exceptions\SqlValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SqlQueryExecutor
{
    private const CONNECTION = 'pgsql_readonly';
    private const MAX_ROWS = 500;
    private const STATEMENT_TIMEOUT_MS = 5000;

    private const ALLOWED_TABLES = [
        'users',
        'organizations',
        'organization_user',
        'teams',
        'team_user',
        'sources',
        'calendar_events',
        'participants',
        'transcript_entries',
        'followups',
        'methodologies',
        'profiles',
        'calendar_event_profile',
    ];

    private const DANGEROUS_KEYWORDS = [
        'INSERT',
        'UPDATE',
        'DELETE',
        'DROP',
        'ALTER',
        'CREATE',
        'TRUNCATE',
        'GRANT',
        'REVOKE',
        'COPY',
        'EXECUTE',
    ];

    private const DATA_TABLES = [
        'followups',
        'sources',
        'calendar_events',
    ];

    public function execute(string $sql, array $accessibleUserIds): SqlQueryResult
    {
        try {
            $this->validate($sql);
            $sql = $this->enforceLimit($sql);
            $sql = $this->injectAccessControl($sql, $accessibleUserIds);

            $connection = DB::connection(self::CONNECTION);
            $connection->statement('SET statement_timeout = ' . self::STATEMENT_TIMEOUT_MS);

            $results = $connection->select($sql);

            Log::info('Wanda SQL executed', [
                'rows' => count($results),
                'sql'  => substr($sql, 0, 500),
            ]);

            return new SqlQueryResult(
                success: true,
                data: array_map(fn($row) => (array) $row, $results),
                rowCount: count($results),
            );
        } catch (SqlValidationException | SqlAccessControlException $e) {
            Log::warning('Wanda SQL validation failed', [
                'error' => $e->getMessage(),
                'sql'   => substr($sql, 0, 500),
            ]);

            return new SqlQueryResult(
                success: false,
                error: $e->getMessage(),
            );
        } catch (\Throwable $e) {
            Log::error('Wanda SQL execution failed', [
                'error' => $e->getMessage(),
                'sql'   => substr($sql, 0, 500),
            ]);

            return new SqlQueryResult(
                success: false,
                error: 'Query execution failed: ' . $e->getMessage(),
            );
        }
    }

    private function validate(string $sql): void
    {
        $sql = trim($sql);

        // Must start with SELECT
        if (!preg_match('/^\s*SELECT\b/i', $sql)) {
            throw new SqlValidationException('Only SELECT statements are allowed');
        }

        // Block dangerous keywords
        $pattern = '/\b(' . implode('|', self::DANGEROUS_KEYWORDS) . ')\b/i';
        if (preg_match($pattern, $sql)) {
            throw new SqlValidationException('SQL contains forbidden keywords');
        }

        // Block INTO (prevents SELECT INTO)
        if (preg_match('/\bINTO\b/i', $sql)) {
            throw new SqlValidationException('INTO keyword is not allowed');
        }

        // Block semicolons (strip string literals first to avoid false positives)
        $sqlWithoutStrings = preg_replace("/'[^']*'/", "''", $sql);
        if (str_contains($sqlWithoutStrings, ';')) {
            throw new SqlValidationException('Semicolons are not allowed');
        }

        // Block comments
        if (str_contains($sql, '--') || str_contains($sql, '/*')) {
            throw new SqlValidationException('SQL comments are not allowed');
        }

        // Check table whitelist
        $this->validateTables($sql);
    }

    private function validateTables(string $sql): void
    {
        // Extract table names from FROM and JOIN clauses
        preg_match_all('/\b(?:FROM|JOIN)\s+([a-z_][a-z0-9_]*)/i', $sql, $matches);

        $tables = array_map('strtolower', $matches[1] ?? []);

        foreach ($tables as $table) {
            if (!in_array($table, self::ALLOWED_TABLES)) {
                throw new SqlValidationException("Table '{$table}' is not allowed");
            }
        }
    }

    private function enforceLimit(string $sql): string
    {
        if (preg_match('/\bLIMIT\s+(\d+)/i', $sql, $matches)) {
            $currentLimit = (int) $matches[1];
            if ($currentLimit > self::MAX_ROWS) {
                $sql = preg_replace('/\bLIMIT\s+\d+/i', 'LIMIT ' . self::MAX_ROWS, $sql);
            }
        } else {
            $sql = rtrim($sql) . ' LIMIT ' . self::MAX_ROWS;
        }

        return $sql;
    }

    private function injectAccessControl(string $sql, array $accessibleUserIds): string
    {
        $idsList = implode(',', array_map('intval', $accessibleUserIds));

        $touchesDataTables = preg_match(
            '/\b(' . implode('|', self::DATA_TABLES) . ')\b/i',
            $sql
        );
        $hasPlaceholder = str_contains($sql, '__ACCESSIBLE_USER_IDS__');

        if ($touchesDataTables && !$hasPlaceholder) {
            throw new SqlAccessControlException(
                'Query references data tables but does not include __ACCESSIBLE_USER_IDS__ access filter'
            );
        }

        return str_replace('__ACCESSIBLE_USER_IDS__', $idsList, $sql);
    }
}
