<?php

namespace App\Services\Agent\Tools;

use App\Enums\ConversationChannelType;
use App\Models\Issue;
use App\Models\User;
use App\Services\Channel\ChannelRuntimeService;
use App\Services\Channel\UserChannelTargetResolver;
use Illuminate\Support\Facades\Auth;

/**
 * Deletes (soft-deletes) an existing issue/task by ID.
 *
 * Authorization rules:
 *  - The requesting user must be the task creator (user_id), OR
 *  - The requesting user must be an organization manager for the task's organization.
 *
 * No user-approval gate: the tool works identically whether it is
 * invoked by an explicit user request or autonomously by the agent.
 *
 * After a successful delete the assignee (if any, and if different from the
 * actor) is notified via Telegram and the web-chat admin UI.
 *
 * Example prompts this tool answers:
 * - "Удали задачу #42"
 * - "Delete task #42"
 * - "Remove issue 100"
 */
class DeleteIssueTool extends AbstractAgentTool
{
    public function __construct(
        private readonly ?User $user = null,
        private readonly ?UserChannelTargetResolver $targetResolver = null,
        private readonly ?ChannelRuntimeService $channelRuntimeService = null,
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
        return 'Delete (soft-delete) a task/issue by its ID. '
            . 'Only the task creator or an organization manager may delete a task. '
            . 'Works without user confirmation — safe to call autonomously during agent-task flows. '
            . 'After deletion the assignee is notified via Telegram and the admin UI if a conversation is available.';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['task_id'],
            'properties' => [
                'task_id' => [
                    'type'        => 'integer',
                    'description' => 'The ID of the task/issue to delete.',
                ],
            ],
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

        // ── Authorization ─────────────────────────────────────────────────────
        $isOwner   = (int) ($issue->user_id ?? 0) === (int) $user->id;
        $isManager = $issue->organization_id !== null
            && $user->isOrganizationManager((int) $issue->organization_id);

        if (! $isOwner && ! $isManager) {
            return [
                'success' => false,
                'error'   => "Permission denied: you must be the task creator or an organization manager to delete task #{$taskId}.",
            ];
        }
        // ─────────────────────────────────────────────────────────────────────

        // Capture metadata before the record disappears
        $snapshot = [
            'id'              => $issue->id,
            'name'            => $issue->name,
            'status'          => $issue->status,
            'organization_id' => $issue->organization_id,
            'team_id'         => $issue->team_id,
            'assignee_id'     => $issue->assignee_id,
        ];

        // Soft-delete via SoftDeletes trait (sets deleted_at timestamp)
        $issue->delete();

        // ── Assignee notification (best-effort) ───────────────────────────────
        $this->notifyAssignee($snapshot, $user);
        // ─────────────────────────────────────────────────────────────────────

        return [
            'success'      => true,
            'task_id'      => $snapshot['id'],
            'name'         => $snapshot['name'],
            'message'      => "Task #{$snapshot['id']} \"{$snapshot['name']}\" has been deleted successfully.",
            'deleted_task' => $snapshot,
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function resolveCurrentUser(): ?User
    {
        $user = $this->user ?? Auth::user();

        return $user instanceof User ? $user : null;
    }

    /**
     * Send a best-effort notification to the assignee (if any) on both
     * Telegram and the web-chat admin UI. Failures are swallowed so that
     * a missing conversation never blocks the delete response.
     *
     * @param array<string, mixed> $snapshot
     */
    private function notifyAssignee(array $snapshot, User $actor): void
    {
        $assigneeId = $snapshot['assignee_id'] ?? null;

        if (! $assigneeId || ! $this->targetResolver || ! $this->channelRuntimeService) {
            return;
        }

        $assignee = User::find($assigneeId);

        // No need to notify if we cannot find the assignee or they are the actor
        if (! $assignee || (int) $assignee->id === (int) $actor->id) {
            return;
        }

        $content = "🗑 Task **#{$snapshot['id']} — {$snapshot['name']}** has been deleted by {$actor->name}.";

        foreach ([ConversationChannelType::TELEGRAM, ConversationChannelType::WEB_CHAT] as $channelType) {
            try {
                $conversation = $this->targetResolver->resolve($assignee, $channelType);

                if ($conversation === null) {
                    continue;
                }

                $this->channelRuntimeService->deliverToConversation(
                    $conversation,
                    $content,
                    null,
                    [
                        'metadata' => [
                            'source'    => 'agent_tool',
                            'tool_name' => $this->getName(),
                            'task_id'   => $snapshot['id'],
                        ],
                    ],
                );
            } catch (\Throwable) {
                // Best-effort — never let notification errors block the delete response
            }
        }
    }
}