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
use App\Services\IssueMergeService;
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
                'name'          => mb_substr(trim($decision->text), 0, 80),
                'description'   => $this->buildDecisionDescription($decision),
                'type'          => 'organization',
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

        $openIssues = Issue::query()
            ->where('team_id', $this->team->id)
            ->where('status', '!=', MeetingTaskStatus::DONE->value)
            ->get(['id', 'name', 'description']);

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

        $prompt = <<<TXT
        Для каждого решения определи, какая открытая задача из списка его покрывает.
        Решение покрыто, если задача напрямую реализует то, что в решении сформулировано.
        Если ни одна задача не покрывает решение — верни issue_id = null.

        Решения:
        {$decisionsList}

        Открытые задачи команды:
        {$issuesList}

        Верни JSON: {"items":[{"decision_id": <id>, "issue_id": <id|null>}, ...]}
        Возвращай по одному элементу для каждого решения.
        TXT;

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
