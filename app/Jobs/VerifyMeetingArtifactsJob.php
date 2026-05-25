<?php

namespace App\Jobs;

use App\Domain\DTO\AI\MessageDTO;
use App\Enums\MeetingTaskStatus;
use App\Models\CalendarEvent;
use App\Models\Decision;
use App\Models\Issue;
use App\Models\Setting;
use App\Models\Team;
use App\Models\User;
use App\Services\Issue\IncompleteIssuesNotifier;
use App\Services\Issue\IssueAutoPipelineDispatcher;
use App\Services\IssueMergeService;
use App\Services\LlmPromptService;
use App\Services\Meeting\MeetingSummaryService;
use App\Services\OpenRouterClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VerifyMeetingArtifactsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public CalendarEvent $event,
        public Team $team,
        public User $user,
        public ?int $forceTelegramUserId = null,
    ) {}

    public function handle(
        MeetingSummaryService $summaryService,
        IssueMergeService $issueMerge,
        IncompleteIssuesNotifier $notifier,
    ): void {
        $this->ensureSummary($summaryService);
        $this->gapFillUncoveredDecisions($issueMerge);
        $unresolvedDecisions = $this->linkOrReportUncoveredDecisions();
        $incomplete = $this->collectIncompleteIssues();

        if ($incomplete->isNotEmpty() || $unresolvedDecisions->isNotEmpty()) {
            $notifier->notify(
                $this->event,
                $incomplete,
                $unresolvedDecisions,
                $this->forceTelegramUserId,
            );
        }

        ExtractEpicsFromTranscriptJob::dispatch($this->event, $this->team, $this->user);

        // Auto-pipeline (validator + detector). The detector is responsible for firing
        // MeetingArtifactsReady at the end of its chain — never emit it here, otherwise
        // SendMeetingSummaryNotification renders before MeetingSummary.conflicts is populated.
        $meetingIssueIds = Issue::forMeeting($this->event->id)->pluck('id')->all();
        app(IssueAutoPipelineDispatcher::class)->dispatchForMeeting($this->event, $meetingIssueIds);

        Log::info('VerifyMeetingArtifactsJob: done', [
            'calendar_event_id'   => $this->event->id,
            'incomplete_count'    => $incomplete->count(),
            'unresolved_count'    => $unresolvedDecisions->count(),
        ]);
    }

    private function ensureSummary(MeetingSummaryService $summaryService): void
    {
        $summary = $this->event->meetingSummary()->first();

        $needsRegen = ! $summary
            || empty($summary->key_points)
            || empty($summary->decisions);

        if ($needsRegen) {
            Log::info('VerifyMeetingArtifactsJob: regenerating summary', [
                'calendar_event_id' => $this->event->id,
            ]);
            $summaryService->generate($this->event);
        }
    }

    private function gapFillUncoveredDecisions(IssueMergeService $issueMerge): void
    {
        $uncovered = $this->getUncoveredDecisions();

        if ($uncovered->isEmpty()) {
            return;
        }

        foreach ($uncovered as $decision) {
            $items = [[
                // Safety guard against issues.name varchar(255) — leaves a few chars headroom
                // for the "…" ellipsis. Typical decision.text fits well under 250 and is stored
                // intact; only genuinely long sentences get word-boundary truncated.
                'name'          => $this->truncateToWord(trim($decision->text), 250),
                'description'   => $this->buildDecisionDescription($decision),
                'type'          => 'organization',
                // author_name carries the decision speaker so IssueMergeService can attribute
                // both `issues.user_id` and `issue_comments.user_id` (US-6.7, US-6.8).
                'author_name'   => $decision->author_raw_name,
                'assignee_name' => $decision->author_raw_name,
                'due_date'      => null,
                'priority'      => 'normal',
            ]];

            $persisted = $issueMerge->persist($items, $this->event, $this->team, $this->user);

            foreach ($persisted as $issue) {
                $exists = DB::table('decision_issue')
                    ->where('decision_id', $decision->id)
                    ->where('issue_id', $issue->id)
                    ->exists();
                if (! $exists) {
                    DB::table('decision_issue')->insert([
                        'decision_id' => $decision->id,
                        'issue_id'    => $issue->id,
                        'created_at'  => now(),
                    ]);
                }

                // US-6.10: stamp the canonical protocol-item link on the issue itself,
                // but only if it's still empty so the first decision wins (multi-coverage
                // is still discoverable through decision_issue pivot).
                DB::table('issues')
                    ->where('id', $issue->id)
                    ->whereNull('source_protocol_item_id')
                    ->update(['source_protocol_item_id' => $decision->id]);
            }
        }

        Log::info('VerifyMeetingArtifactsJob: gap-filled uncovered decisions', [
            'calendar_event_id' => $this->event->id,
            'count'             => $uncovered->count(),
        ]);
    }

    private function getUncoveredDecisions(): \Illuminate\Support\Collection
    {
        $decisionIds = Decision::where('calendar_event_id', $this->event->id)->pluck('id');

        if ($decisionIds->isEmpty()) {
            return collect();
        }

        $coveredIds = DB::table('decision_issue')
            ->whereIn('decision_id', $decisionIds)
            ->pluck('decision_id')
            ->unique();

        return Decision::whereIn('id', $decisionIds)
            ->whereNotIn('id', $coveredIds)
            ->get();
    }

    private function buildDecisionDescription(Decision $decision): string
    {
        $title = $this->event->title ?? 'встреча';
        $date  = optional($this->event->starts_at)->toDateString() ?? '';
        $topic = $decision->topic ?: '—';

        return "## Контекст\nЗадача порождена решением, принятым на встрече \"{$title}\" ({$date}).\nТема решения: {$topic}.\n\n## Текст решения\n{$decision->text}\n\n## Definition of done\nРешение реализовано, исполнитель зафиксировал результат.";
    }

    /**
     * Truncate at the last space before `$maxLen` and append «…», so an issue.name
     * lifted from decision.text never ends mid-word in the UI (Telegram, dashboard).
     * Falls back to a hard cut if the chunk has no whitespace before the limit
     * (otherwise short single-token decisions could disappear entirely).
     */
    private function truncateToWord(string $text, int $maxLen): string
    {
        if (mb_strlen($text) <= $maxLen) {
            return $text;
        }

        $cut = mb_substr($text, 0, $maxLen);
        $lastSpace = mb_strrpos($cut, ' ');

        // Only use the word boundary if it preserves at least 60% of the budget —
        // otherwise a tiny snippet would be ellipsized away.
        if ($lastSpace !== false && $lastSpace >= (int) ($maxLen * 0.6)) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut, " \t\n\r,;:.—-") . '…';
    }

    /**
     * Second-chance linking for decisions still uncovered after gap-fill (e.g. when
     * IssueMergeService returned `skip` because an existing issue already covers them).
     * Asks LLM to map remaining uncovered decisions to open team issues.
     *
     * @return Collection<int, Decision> decisions that even LLM could not cover
     */
    private function linkOrReportUncoveredDecisions(): Collection
    {
        $stillUncovered = $this->getUncoveredDecisions();

        if ($stillUncovered->isEmpty()) {
            return collect();
        }

        // See IssueMergeService::merge — partial select breaks Issue::saving hook (would
        // demote epic types). Load full models even though only id/name/description are read here,
        // because callers downstream may save these models.
        $openIssues = Issue::query()
            ->where('team_id', $this->team->id)
            ->where('status', '!=', MeetingTaskStatus::DONE->value)
            ->get();

        if ($openIssues->isEmpty()) {
            return $stillUncovered;
        }

        $mapping = $this->askLlmForCoverage($stillUncovered, $openIssues);

        $unresolved = collect();
        foreach ($stillUncovered as $decision) {
            $issueId = $mapping[$decision->id] ?? null;
            if (! $issueId || ! $openIssues->contains('id', $issueId)) {
                $unresolved->push($decision);
                continue;
            }

            $exists = DB::table('decision_issue')
                ->where('decision_id', $decision->id)
                ->where('issue_id', $issueId)
                ->exists();
            if (! $exists) {
                DB::table('decision_issue')->insert([
                    'decision_id' => $decision->id,
                    'issue_id'    => $issueId,
                    'created_at'  => now(),
                ]);
            }

            // US-6.10: stamp the canonical protocol-item link, first decision wins.
            DB::table('issues')
                ->where('id', $issueId)
                ->whereNull('source_protocol_item_id')
                ->update(['source_protocol_item_id' => $decision->id]);
        }

        if ($unresolved->isNotEmpty()) {
            Log::info('VerifyMeetingArtifactsJob: decisions still uncovered after LLM linking', [
                'calendar_event_id' => $this->event->id,
                'count'             => $unresolved->count(),
            ]);
        }

        return $unresolved;
    }

    /**
     * @return array<int, int|null> map of decision_id => covering issue_id|null
     */
    private function askLlmForCoverage(Collection $decisions, Collection $issues): array
    {
        $decisionsList = $decisions->map(fn (Decision $d) => sprintf(
            "id=%d topic=%s text=%s",
            $d->id,
            $d->topic ?? '-',
            $d->text,
        ))->implode("\n");

        $issuesList = $issues->map(fn (Issue $i) => sprintf(
            "id=%d name=%s description=%s",
            $i->id,
            $i->name,
            mb_substr((string) $i->description, 0, 250),
        ))->implode("\n");

        $prompt = app(LlmPromptService::class)->renderView(
            slug: 'meeting.verify_artifacts.coverage.user',
            organizationId: $this->event->source?->organization_id,
            fallbackView: 'llm-prompts.meeting.verify-artifacts-coverage-user',
            variables: [
                'decisions' => $decisionsList,
                'issues' => $issuesList,
            ],
            name: 'Meeting artifact coverage prompt',
        );

        try {
            $json = app(OpenRouterClient::class)->chat(
                messages: [new MessageDTO('user', $prompt)],
                model: Setting::get('model.meeting_tasks', config('ai.providers.openrouter.models.meeting_tasks')),
                maxTokens: 2048,
                forceJsonResponse: true,
            );
        } catch (\Throwable $e) {
            Log::warning('VerifyMeetingArtifactsJob: LLM coverage call failed', [
                'calendar_event_id' => $this->event->id,
                'error'             => $e->getMessage(),
            ]);
            return [];
        }

        if (! preg_match('/\{[\s\S]*\}/s', (string) $json, $matches)) {
            return [];
        }
        $data = json_decode($matches[0], true);
        $items = $data['items'] ?? [];

        $map = [];
        foreach ($items as $row) {
            $decisionId = $row['decision_id'] ?? null;
            $issueId    = $row['issue_id'] ?? null;
            if ($decisionId !== null) {
                $map[(int) $decisionId] = $issueId !== null ? (int) $issueId : null;
            }
        }

        return $map;
    }

    private function collectIncompleteIssues()
    {
        $meetingIssueIds = Issue::forMeeting($this->event->id)->pluck('id');

        $decisionIds = Decision::where('calendar_event_id', $this->event->id)->pluck('id');
        $decisionIssueIds = DB::table('decision_issue')
            ->whereIn('decision_id', $decisionIds)
            ->pluck('issue_id');

        $allIds = $meetingIssueIds->merge($decisionIssueIds)->unique();

        if ($allIds->isEmpty()) {
            return collect();
        }

        return Issue::whereIn('id', $allIds)
            ->where(function ($q) {
                $q->whereNull('assignee_id')->orWhereNull('due_date');
            })
            ->get();
    }
}
