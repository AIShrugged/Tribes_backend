<?php

namespace App\Services\Decisions;

use App\Domain\DTO\AI\MessageDTO;
use App\Models\CalendarEvent;
use App\Models\Decision;
use App\Models\Issue;
use App\Models\Setting;
use App\Services\OpenRouterClient;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LinkDecisionsToIssuesService
{
    public function link(CalendarEvent $event, Collection $issues): int
    {
        $decisions = Decision::where('calendar_event_id', $event->id)->get();

        if ($decisions->isEmpty() || $issues->isEmpty()) {
            return 0;
        }

        $mapping = $this->askLlmForCoverage($decisions, $issues);
        if (empty($mapping)) {
            return 0;
        }

        $linksCreated = 0;
        DB::transaction(function () use ($decisions, $issues, $mapping, &$linksCreated) {
            $issuesById = $issues->keyBy('id');

            foreach ($mapping as $row) {
                $decisionId = $row['decision_id'] ?? null;
                $issueIds   = $row['covering_issue_ids'] ?? [];

                $decision = $decisions->firstWhere('id', $decisionId);
                if (! $decision || empty($issueIds)) {
                    continue;
                }

                $validIssueIds = collect($issueIds)
                    ->filter(fn ($id) => $issuesById->has($id))
                    ->values()
                    ->all();

                if (empty($validIssueIds)) {
                    continue;
                }

                $existingLinks = $decision->issues()->whereIn('issues.id', $validIssueIds)->pluck('issues.id')->all();
                $newLinks = array_diff($validIssueIds, $existingLinks);

                if (! empty($newLinks)) {
                    $decision->issues()->attach($newLinks);
                    $linksCreated += count($newLinks);
                }
            }
        });

        return $linksCreated;
    }

    /**
     * @return array<int, array{decision_id: int, covering_issue_ids: int[]}>
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
        Для каждого решения определи, какие задачи (issues) его покрывают.
        Решение покрыто, если задача напрямую реализует то, что в решении сформулировано.
        Если решение НЕ покрыто ни одной задачей — верни пустой массив covering_issue_ids.

        Решения:
        {$decisionsList}

        Задачи (issues):
        {$issuesList}

        Верни JSON: {"items":[{"decision_id": <id>, "covering_issue_ids": [<id>,...]}, ...]}
        Возвращай по одному элементу для каждого решения, в том же порядке.
        TXT;

        try {
            $json = OpenRouterClient::chat(
                messages: [new MessageDTO('user', $prompt)],
                model: Setting::get('model.meeting_tasks', config('ai.providers.openrouter.models.meeting_tasks')),
                maxTokens: 2048,
                forceJsonResponse: true,
            );
        } catch (\Throwable $e) {
            Log::warning('LinkDecisionsToIssuesService: LLM call failed', ['error' => $e->getMessage()]);
            return [];
        }

        if (! preg_match('/\{[\s\S]*\}/s', $json, $matches)) {
            return [];
        }
        $data = json_decode($matches[0], true);

        return $data['items'] ?? [];
    }
}
