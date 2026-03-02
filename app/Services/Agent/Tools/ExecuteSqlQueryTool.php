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
        return 'Execute a read-only SQL SELECT query against the database. '
            . 'Use this as a universal fallback when no specialized tool covers the question, '
            . 'or when you need to join data from multiple tables in one request. '
            . 'The full database schema is available in the system prompt under "## Database Schema". '
            . 'Only SELECT statements are allowed. '
            . 'Use the __ACCESSIBLE_USER_IDS__ placeholder in WHERE clauses when querying '
            . 'followups, sources, or calendar_events to enforce row-level access control. '
            . 'Example: SELECT u.name, t.name AS team FROM users u JOIN team_user tu ON tu.user_id = u.id JOIN teams t ON t.id = tu.team_id WHERE t.name ILIKE \'%backenders%\'';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'sql' => [
                    'type'        => 'string',
                    'description' => 'The SELECT SQL query to execute. Must be read-only. '
                        . 'Use __ACCESSIBLE_USER_IDS__ in WHERE clause when querying followups, sources, or calendar_events. '
                        . 'Example: SELECT * FROM meeting_tasks WHERE calendar_event_id = 42',
                ],
            ],
            'required' => ['sql'],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $parameters = $parameters ?? [];

        $sql = $parameters['sql'] ?? '';

        if (empty($sql)) {
            return [
                'success' => false,
                'error'   => 'SQL query is required',
            ];
        }

        try {
            $executor = new SqlQueryExecutor;
            $result   = $executor->execute($sql, [$this->userId]);

            if (! $result->success) {
                return [
                    'success' => false,
                    'error'   => $result->error,
                ];
            }

            return [
                'success'   => true,
                'data'      => $result->data,
                'row_count' => $result->rowCount,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }
}
