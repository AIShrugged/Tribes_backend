<?php

namespace App\Services\Agent\Tools;

use App\Models\Issue;

/**
 * Deletes (soft-deletes) an existing task/issue by ID.
 *
 * Example questions this tool answers:
 * - "Delete task #42"
 * - "Remove issue #7 — it's no longer relevant"
 * - "Удали задачу #15"
 *
 * The deletion is a soft-delete: the record is flagged with `deleted_at`
 * and excluded from all standard queries, but remains recoverable.
 * The IssueObserver fires post-delete notifications to the assignee
 * via Telegram and the admin UI automatically.
 */
class DeleteTaskTool extends AbstractAgentTool
{
    public function getName(): string
    {
        return 'delete_task';
    }

    public function getDescription(): string
    {
        return 'Delete (soft-delete) an existing task by its ID. The assignee is automatically notified via Telegram and the admin UI. Use when the user explicitly asks to remove or delete a task, or when the agent determines the task is no longer needed during an autonomous workflow.';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'task_id' => [
                    'type'        => 'integer',
                    'description' => 'The ID of the task (issue) to delete.',
                ],
            ],
            'required' => ['task_id'],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $taskId = $parameters['task_id'] ?? null;

        if (! $taskId) {
            return ['success' => false, 'error' => 'task_id is required'];
        }

        $issue = Issue::find($taskId);

        if (! $issue) {
            return ['success' => false, 'error' => "Task #{$taskId} not found"];
        }

        $snapshot = [
            'task_id' => $issue->id,
            'name'    => $issue->name,
            'status'  => $issue->status,
            'type'    => $issue->type,
        ];

        $issue->delete();

        return [
            'success' => true,
            'message' => "Task #{$snapshot['task_id']} \"{$snapshot['name']}\" has been deleted.",
            'deleted' => $snapshot,
        ];
    }
}