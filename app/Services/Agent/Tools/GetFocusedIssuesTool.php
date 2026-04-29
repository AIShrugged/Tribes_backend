<?php

namespace App\Services\Agent\Tools;

use App\Models\Issue;
use App\Models\Profile;
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
        return 'Get the current user\'s focused tasks — issues that match their active focus text via full-text search, plus critical-priority issues (priority >= 500) as fallback. '
             . 'Returns focused_tasks (keyword-matched), fallback_tasks (critical priority), has_focus flag, and matched_count. '
             . 'Call this when the user asks about focused tasks, their priorities, or what to work on next.';
    }

    public function getParameters(): array
    {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }

    public function execute(?array $parameters): mixed
    {
        $focus = $this->userFocusService->getFocus($this->profile);
        $hasFocus = $focus !== null && ! empty($focus->content['focus_text']);
        $focusText = $hasFocus ? ($focus->content['focus_text'] ?? '') : '';

        $focusedTasks = [];

        if ($hasFocus && $focusText !== '') {
            $focusedIssues = Issue::query()
                ->whereNotIn('status', ['done'])
                ->whereRaw(
                    "to_tsvector('russian', coalesce(name, '') || ' ' || coalesce(description, '')) @@ plainto_tsquery('russian', ?)",
                    [$focusText]
                )
                ->where(function ($q) {
                    $q->where('assignee_id', $this->userId)
                      ->orWhere('user_id', $this->userId);
                })
                ->orderBy('priority', 'desc')
                ->limit(10)
                ->get();

            $focusedTasks = $focusedIssues->map(fn (Issue $i) => $this->formatIssue($i))->toArray();
        }

        $fallbackIssues = Issue::query()
            ->whereNotIn('status', ['done'])
            ->where('priority', '>=', Issue::PRIORITY_CRITICAL)
            ->where(function ($q) {
                $q->where('assignee_id', $this->userId)
                  ->orWhere('user_id', $this->userId);
            })
            ->orderBy('priority', 'desc')
            ->limit(5)
            ->get();

        $fallbackTasks = $fallbackIssues->map(fn (Issue $i) => $this->formatIssue($i))->toArray();

        return [
            'success'        => true,
            'has_focus'      => $hasFocus,
            'focus_text'     => $focusText,
            'matched_count'  => count($focusedTasks),
            'focused_tasks'  => $focusedTasks,
            'fallback_tasks' => $fallbackTasks,
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
            'assignee_name' => $issue->assignee_name,
        ];
    }
}