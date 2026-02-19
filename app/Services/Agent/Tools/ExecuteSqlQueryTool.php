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
        return 'Execute a read-only SQL SELECT query to retrieve data from the database. Only SELECT statements are allowed. '
            . 'Available tables: users, organizations, organization_user (pivot), teams, team_user (pivot), '
            . 'profiles, channels, sources, source_oauths, '
            . 'calendar_events (columns: id, source_id, external_id, platform, starts_at, ends_at, title, description, required_bot), '
            . 'participants (columns: id, calendar_event_id, profile_id, name), '
            . 'transcript_entries, meeting_summaries, meeting_tasks (columns: id, calendar_event_id, profile_id, title, description, assignee_name, due_date, status), '
            . 'followups, methodologies, '
            . 'insight_sources, insight_items, insight_profiles, insight_profile_history, insight_relationships, insight_short_term. '
            . 'Use __ACCESSIBLE_USER_IDS__ placeholder when querying followups, sources, or calendar_events to filter by user access. '
            . 'Example: SELECT id, title, starts_at, ends_at FROM calendar_events WHERE source_id IN (SELECT id FROM sources WHERE user_id IN (__ACCESSIBLE_USER_IDS__)) ORDER BY starts_at DESC LIMIT 10';
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