<?php

namespace App\Services\Agent\Tools;

use App\Services\Chat\SqlQueryExecutor;

class ExecuteSqlQueryTool implements ToolInterface
{
    public function __construct(private readonly int $userId)
    {
    }

    public function getName(): string
    {
        return 'execute_sql_query';
    }

    public function getDescription(): string
    {
        return 'Execute a read-only SQL SELECT query to retrieve data from the database. Only SELECT statements are allowed. You can query tables: users, organizations, teams, sources, calendar_events, participants, transcript_entries, followups, methodologies, profiles. Use __ACCESSIBLE_USER_IDS__ placeholder when querying data tables (followups, sources, calendar_events) to filter by user access.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sql' => [
                    'type' => 'string',
                    'description' => 'The SELECT SQL query to execute. Must be read-only. Use __ACCESSIBLE_USER_IDS__ in WHERE clause when querying followups, sources, or calendar_events tables. Example: SELECT * FROM followups WHERE user_id IN (__ACCESSIBLE_USER_IDS__) LIMIT 10',
                ],
            ],
            'required' => ['sql'],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        // Handle null parameters
        $parameters = $parameters ?? [];

        $sql = $parameters['sql'] ?? '';

        if (empty($sql)) {
            return [
                'success' => false,
                'error' => 'SQL query is required',
            ];
        }

        try {
            $accessibleUserIds = [$this->userId];

            $executor = new SqlQueryExecutor;
            $result = $executor->execute($sql, $accessibleUserIds);

            if (! $result->success) {
                return [
                    'success' => false,
                    'error' => $result->error,
                ];
            }

            return [
                'success' => true,
                'data' => $result->data,
                'row_count' => $result->rowCount,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}