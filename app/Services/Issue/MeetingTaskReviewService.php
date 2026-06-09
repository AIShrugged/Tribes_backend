<?php

namespace App\Services\Issue;

use App\Domain\DTO\AI\MessageDTO;
use App\Models\CalendarEvent;
use App\Models\Issue;
use App\Models\MeetingTaskReview;
use App\Models\MeetingTaskReviewItem;
use App\Models\Setting;
use App\Services\Followup\TranscriptBuilderService;
use App\Services\LlmPromptService;
use App\Services\OpenRouterClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class MeetingTaskReviewService
{
    public function __construct(
        private readonly OpenRouterClient $llm,
        private readonly TranscriptBuilderService $transcriptBuilder,
        private readonly LlmPromptService $promptService,
    ) {}

    public function generate(CalendarEvent $calendarEvent, int $organizationId): MeetingTaskReview
    {
        $review = MeetingTaskReview::updateOrCreate(
            ['calendar_event_id' => $calendarEvent->id],
            [
                'organization_id' => $organizationId,
                'status' => 'pending',
                'results' => null,
                'error' => null,
                'analyzed_count' => 0,
            ]
        );

        $transcript = $this->transcriptBuilder->build($calendarEvent);

        if (blank($transcript)) {
            $review->update([
                'status' => 'failed',
                'error' => 'No transcript available',
            ]);

            return $review;
        }

        $issues = Issue::query()
            ->activeForNudging()
            ->inOrganization($organizationId)
            ->withoutTrashed()
            ->orderBy('id')
            ->limit(100)
            ->get(['id', 'name', 'status', 'assignee_id', 'due_date', 'description', 'updated_at']);

        if ($issues->isEmpty()) {
            $review->update([
                'status' => 'done',
                'results' => collect(),
                'analyzed_count' => 0,
            ]);

            return $review;
        }

        try {
            $issuesList = $issues->map(fn ($i) => [
                'id' => $i->id,
                'name' => $i->name,
                'status' => $i->status,
            ])->values()->all();

            $messages = [
                new MessageDTO('system', $this->buildSystemPrompt()),
                new MessageDTO('user', "Дата встречи: {$calendarEvent->starts_at->toDateString()}\nТекущая дата: ".now()->toDateString()."\n\n## Список задач для анализа:\n".json_encode($issuesList, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n\n## Транскрипт встречи:\n".$transcript),
            ];

            $json = $this->llm->chat(
                messages: $messages,
                model: Setting::get('model.meeting_task_review', config('ai.providers.openrouter.models.meeting_task_review')),
                maxTokens: 4096,
                forceJsonResponse: true,
            );

            if (is_string($json) && preg_match('/\{[\s\S]*\}/s', $json, $matches)) {
                $json = $matches[0];
            }

            $decoded = is_string($json) ? json_decode($json, true) : $json;
            $results = $decoded['results'] ?? [];

            $mentioned = array_filter($results, fn($r) => ($r['progress'] ?? '') !== 'not_mentioned');

            $review->items()->delete();
            foreach ($mentioned as $result) {
                MeetingTaskReviewItem::create([
                    'meeting_task_review_id' => $review->id,
                    'issue_id' => $result['issue_id'],
                    'progress' => $result['progress'],
                    'confidence' => $result['confidence'] ?? 'low',
                    'notes' => $result['notes'] ?? null,
                ]);
            }

            $review->update([
                'status' => 'done',
                'analyzed_count' => $issues->count(),
            ]);

            Log::info('Meeting task review generated', [
                'calendar_event_id' => $calendarEvent->id,
                'organization_id' => $organizationId,
                'analyzed_count' => $issues->count(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Meeting task review generation failed', [
                'calendar_event_id' => $calendarEvent->id,
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);

            $review->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);
        }

        return $review;
    }

    /**
     * Compute health blocks from the review results + fresh issue data.
     *
     * @return array<int, array{type: string, label: string, issues: array}>
     */
    public function getBlocks(MeetingTaskReview $review): array
    {
        $organizationId = $review->organization_id;

        // Load issues with latest_comment_at for stuck calculation
        $issuesMap = Issue::query()
            ->activeForNudging()
            ->inOrganization($organizationId)
            ->withoutTrashed()
            ->withMax('allComments as latest_comment_at', 'created_at')
            ->with(['assignee'])
            ->orderBy('id')
            ->get()
            ->keyBy('id')
            ->all();

        if (empty($issuesMap)) {
            return [];
        }

        $blocks = [];

        $resultsMap = $review->items->keyBy('issue_id');

        foreach ($issuesMap as $issue) {
            $lastMovement = $this->lastMovement($issue);
            $daysInactive = (int) $lastMovement->diffInDays(Carbon::now());

            $item = $resultsMap->get($issue->id);

            // status_not_updated: LLM said done but status is not done
            if ($item && $item->progress === 'done' && $issue->status !== 'done') {
                $blocks['status_not_updated'][] = [
                    'id' => $issue->id,
                    'name' => $issue->name,
                    'status' => $issue->status,
                    'assignee_id' => $issue->assignee_id,
                    'assignee_name' => $issue->assignee?->name,
                    'due_date' => $issue->due_date,
                    'days_inactive' => $daysInactive,
                    'suggestion' => 'update_status',
                    'transcript_evidence' => $item->notes,
                ];
            }

            // blocked: LLM said blocked
            if ($item && $item->progress === 'blocked') {
                $blocks['blocked'][] = [
                    'id' => $issue->id,
                    'name' => $issue->name,
                    'status' => $issue->status,
                    'assignee_id' => $issue->assignee_id,
                    'assignee_name' => $issue->assignee?->name,
                    'due_date' => $issue->due_date,
                    'days_inactive' => $daysInactive,
                    'suggestion' => 'resolve_blocker',
                    'transcript_evidence' => $item->notes,
                ];
            }

            // no_assignee
            if (!$issue->assignee_id) {
                $blocks['no_assignee'][] = [
                    'id' => $issue->id,
                    'name' => $issue->name,
                    'status' => $issue->status,
                    'assignee_id' => null,
                    'assignee_name' => null,
                    'due_date' => $issue->due_date,
                    'days_inactive' => $daysInactive,
                    'suggestion' => 'reassign',
                    'transcript_evidence' => null,
                ];
            }

            // incomplete_info
            if (blank($issue->description) || strlen(trim($issue->name)) < 5) {
                $blocks['incomplete_info'][] = [
                    'id' => $issue->id,
                    'name' => $issue->name,
                    'status' => $issue->status,
                    'assignee_id' => $issue->assignee_id,
                    'assignee_name' => $issue->assignee?->name,
                    'due_date' => $issue->due_date,
                    'days_inactive' => $daysInactive,
                    'suggestion' => 'fill_info',
                    'transcript_evidence' => null,
                ];
            }

            // overdue
            if ($issue->due_date && Carbon::parse($issue->due_date)->lt(Carbon::today())) {
                $blocks['overdue'][] = [
                    'id' => $issue->id,
                    'name' => $issue->name,
                    'status' => $issue->status,
                    'assignee_id' => $issue->assignee_id,
                    'assignee_name' => $issue->assignee?->name,
                    'due_date' => $issue->due_date,
                    'days_inactive' => $daysInactive,
                    'suggestion' => 'update_due_date',
                    'transcript_evidence' => null,
                ];
            }

            // stuck_in_progress: in [in_progress, review, reopen] for 6+ days
            if (in_array($issue->status, ['in_progress', 'review', 'reopen']) && $daysInactive >= 6) {
                $blocks['stuck_in_progress'][] = [
                    'id' => $issue->id,
                    'name' => $issue->name,
                    'status' => $issue->status,
                    'assignee_id' => $issue->assignee_id,
                    'assignee_name' => $issue->assignee?->name,
                    'due_date' => $issue->due_date,
                    'days_inactive' => $daysInactive,
                    'suggestion' => 'escalate',
                    'transcript_evidence' => null,
                ];
            }

            // abandoned: any active status for 30+ days
            if ($daysInactive >= 30) {
                $blocks['abandoned'][] = [
                    'id' => $issue->id,
                    'name' => $issue->name,
                    'status' => $issue->status,
                    'assignee_id' => $issue->assignee_id,
                    'assignee_name' => $issue->assignee?->name,
                    'due_date' => $issue->due_date,
                    'days_inactive' => $daysInactive,
                    'suggestion' => 'close',
                    'transcript_evidence' => null,
                ];
            }
        }

        // Format blocks
        $labels = [
            'status_not_updated' => 'Выполнены, но статус не обновлён',
            'blocked' => 'Заблокированы',
            'no_assignee' => 'Нет исполнителя',
            'incomplete_info' => 'Неполная информация',
            'overdue' => 'Просрочены',
            'stuck_in_progress' => 'Застрял в работе',
            'abandoned' => 'Заброшены',
        ];

        $result = [];
        foreach ($blocks as $type => $issues) {
            $result[] = [
                'type' => $type,
                'label' => $labels[$type] ?? $type,
                'count' => count($issues),
                'issues' => array_values($issues),
            ];
        }

        return $result;
    }

    private function lastMovement(Issue $issue): Carbon
    {
        $updated = Carbon::parse($issue->updated_at);
        $latest = $issue->latest_comment_at ? Carbon::parse($issue->latest_comment_at) : null;

        return $latest && $latest->gt($updated) ? $latest : $updated;
    }

    private function buildSystemPrompt(): string
    {
        return $this->promptService->renderView(
            slug: 'issue.task-review.system',
            organizationId: null,
            fallbackView: 'llm-prompts.issue.task-review-system',
            variables: [],
            name: 'Meeting task review system prompt',
        );
    }
}
