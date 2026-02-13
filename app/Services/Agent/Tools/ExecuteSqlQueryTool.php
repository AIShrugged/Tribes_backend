<?php

namespace App\Services\Agent\Tools;

use App\Models\TelegramUser;
use App\Services\Chat\SqlQueryExecutor;

class ExecuteSqlQueryTool implements ToolInterface
{
    private int $telegramUserId;

    public function __construct(int $telegramUserId)
    {
        $this->telegramUserId = $telegramUserId;
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
            $telegramUser = TelegramUser::find($this->telegramUserId);

            if (! $telegramUser) {
                return [
                    'success' => false,
                    'error' => 'Telegram user not found',
                ];
            }

            // Check if user has linked account
            if (! $telegramUser->user_id) {
                return [
                    'success' => false,
                    'error' => 'No linked user account. Cannot execute queries on user data.',
                ];
            }

            // Get accessible user IDs (for now, just the linked user)
            // In the future, this could include team members or organization members
            $accessibleUserIds = [$telegramUser->user_id];

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