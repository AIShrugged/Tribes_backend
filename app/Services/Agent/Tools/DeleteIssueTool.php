<?php

namespace App\Services\Agent\Tools;

use App\Models\Issue;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Deletes a task/issue by ID (soft-delete via SoftDeletes trait).
 *
 * Authorization rules:
 *  - The requesting user is the creator of the issue (user_id), OR
 *  - The requesting user is an organization manager for the issue's organization.
 *
 * The tool works without any user-approval gate so it can be called both
 * by explicit user requests and autonomously during an agent-task flow.
 *
 * Example questions this tool answers:
 * - "Delete task #42"
 * - "Remove issue #7"
 * - "Удали задачу #15"
 */
class DeleteIssueTool extends AbstractAgentTool
{
    public function __construct(
        private readonly ?User $user = null,
        private readonly ?int $organizationId = null,
        private readonly ?int $teamId = null,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'delete_task';
    }

    public function getDescription(): string
    {
        return 'Delete (soft-delete) a task/issue by its ID. The requesting user must be the task creator or an organization manager. Works without user confirmation — safe to call autonomously during agent-task flows.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'task_id' => [
                    'type'        => 'integer',
                    'description' => 'The ID of the task/issue to delete.',
                ],
            ],
            'required' => ['task_id'],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $taskId = isset($parameters['task_id']) ? (int) $parameters['task_id'] : null;

        if (! $taskId) {
            return ['success' => false, 'error' => 'task_id is required'];
        }

        $user = $this->resolveCurrentUser();
        if (! $user) {
            return ['success' => false, 'error' => 'Authenticated user context is required'];
        }

        /** @var Issue|null $issue */
        $issue = Issue::find($taskId);

        if (! $issue) {
            return ['success' => false, 'error' => "Task #{$taskId} not found"];
        }

        // Authorization: owner OR organization manager
        $isOwner   = (int) $issue->user_id === (int) $user->id;
        $isManager = $issue->organization_id !== null
            && $user->isOrganizationManager((int) $issue->organization_id);

        if (! $isOwner && ! $isManager) {
            return [
                'success' => false,
                'error'   => "Permission denied: you must be the task creator or an organization manager to delete task #{$taskId}",
            ];
        }

        // Soft-delete via SoftDeletes trait (sets deleted_at timestamp)
        $issue->delete();

        return [
            'success' => true,
            'task_id' => $issue->id,
            'name'    => $issue->name,
            'message' => "Task #{$issue->id} \"{$issue->name}\" has been deleted successfully",
        ];
    }

    private function resolveCurrentUser(): ?User
    {
        $user = $this->user ?? Auth::user();

        return $user instanceof User ? $user : null;
    }
}