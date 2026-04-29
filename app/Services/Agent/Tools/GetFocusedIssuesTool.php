<?php

namespace App\Services\Agent\Tools;

use App\Models\Issue;
use App\Models\Profile;
use App\Models\User;
use App\Services\UserFocusService;

class GetFocusedIssuesTool implements ToolInterface
{
    public function __construct(
        private readonly Profile $profile,
        private readonly UserFocusService $userFocusService,
        private readonly int $userId,
    ) {}

    public function getName(): string
    {
        return 'get_focused_issues';
    }

    public function getDescription(): string
    {
        return 'Get the current user\'s focused tasks. Resolution order: '
             . '(1) explicit issue_ids snapshot stored when focus was set, in user-given order; '
             . '(2) full-text search over the focus_text for legacy/thematic focus; '
             . '(3) critical-priority open issues as fallback. '
             . 'Returns focused_tasks (the resolved list), has_focus flag, and matched_count. '
             . 'Call this when the user asks about focused tasks, their priorities, or what to work on next.';
    }

    public function getParameters(): array
    {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }

    public function execute(?array $parameters): mixed
    {
        $focus    = $this->userFocusService->getFocus($this->profile);
        $hasFocus = $focus !== null && ! empty($focus->content['focus_text']);
        $focusText = $hasFocus ? ($focus->content['focus_text'] ?? '') : '';

        $user = User::find($this->userId);
        if (! $user) {
            return [
                'success'        => true,
                'has_focus'      => $hasFocus,
                'focus_text'     => $focusText,
                'matched_count'  => 0,
                'focused_tasks'  => [],
            ];
        }

        $issues = $this->userFocusService->getFocusedIssues($user);
        $tasks  = $issues->map(fn (Issue $i) => $this->formatIssue($i))->all();

        return [
            'success'        => true,
            'has_focus'      => $hasFocus,
            'focus_text'     => $focusText,
            'matched_count'  => count($tasks),
            'focused_tasks'  => $tasks,
        ];
    }

    private function formatIssue(Issue $issue): array
    {
        return [
            'id'            => $issue->id,
            'name'          => $issue->name,
            'status'        => $issue->status,
            'priority'      => $issue->priority,
            'due_date'      => $issue->due_date?->toDateString(),
            'assignee_name' => $issue->assignee?->name ?? $issue->assignee_name,
        ];
    }
}
