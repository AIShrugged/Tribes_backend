<?php

namespace App\Services\Agent\Tools;

use App\Enums\MeetingTaskStatus;
use App\Services\Agent\Tools\Concerns\InteractsWithMcpTenant;

/**
 * Updates the status of an existing task.
 *
 * Example questions this tool answers:
 * - "Отметь задачу #5 как выполненную"
 * - "Mark the PR review task as done"
 * - "Переведи задачу в статус in_progress"
 * - "Pause task #12"
 */
class UpdateTaskStatusTool extends AbstractAgentTool
{
    use InteractsWithMcpTenant;

    public function getName(): string
    {
        return 'update_task_status';
    }

    public function getDescription(): string
    {
        return 'Update the status of an existing task. Use when the user wants to mark a task as open, in_progress, paused, review, reopen, or done. Requires the task ID. Set "review" when work is complete and awaiting user review. Set "reopen" when a reviewed task needs rework.';
    }

    public function getParameters(): array
    {
        $validStatuses = array_map(fn ($s) => $s->value, MeetingTaskStatus::cases());

        return [
            'type' => 'object',
            'properties' => [
                'task_id' => [
                    'type'        => 'integer',
                    'description' => 'The ID of the task to update.',
                ],
                'status' => [
                    'type'        => 'string',
                    'enum'        => $validStatuses,
                    'description' => 'The new status. Valid values: ' . implode(', ', $validStatuses) . '.',
                ],
            ],
            'required' => ['task_id', 'status'],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $taskId = $parameters['task_id'] ?? null;
        $status = $parameters['status'] ?? null;

        if (! $taskId || ! $status) {
            return ['success' => false, 'error' => 'task_id and status are required'];
        }

        $issue = $this->assertCanAccessIssue((int) $taskId);
        if (! $issue) {
            return ['success' => false, 'error' => "Task #{$taskId} not found"];
        }

        $validStatuses = array_map(fn ($s) => $s->value, MeetingTaskStatus::cases());
        if (! in_array($status, $validStatuses, true)) {
            return ['success' => false, 'error' => "Invalid status: {$status}. Valid values: " . implode(', ', $validStatuses)];
        }

        $oldStatus = $issue->status;
        $issue->update(['status' => $status]);

        return [
            'success'    => true,
            'task_id'    => $issue->id,
            'name'       => $issue->name,
            'old_status' => $oldStatus,
            'new_status' => $issue->status,
        ];
    }
}
