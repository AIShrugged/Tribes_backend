<?php

namespace App\Services\CriticalPath;

use App\Domain\DTO\AI\MessageDTO;
use App\Models\Issue;
use App\Services\OpenRouterClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class CriticalPathLlmAnalyzer
{
    private const MAX_DESCRIPTION_LENGTH = 400;

    private const SYSTEM_PROMPT = <<<'PROMPT'
Ты — инструмент планирования проектов. Проанализируй список задач и верни ТОЛЬКО валидный JSON без пояснений.

Схема ответа:
{
  "durations": {
    "<issue_id>": <число рабочих дней (float)>
  },
  "implicit_edges": [
    { "from": <id блокирующей задачи>, "to": <id заблокированной задачи> }
  ]
}

Правила для duration (рабочие дни):
- Баг или мелкая правка конфигурации: 0.5–1
- Простая задача с понятным описанием: 1–2
- Feature со средней сложностью: 2–5
- Крупная feature или рефакторинг: 5–10
- Задача с CRITICAL приоритетом без описания: 3
- НЕ включай задачи со статусом done

Правила для implicit_edges:
- Добавляй только связи, которых НЕТ в списке explicit_blockers
- Выводи зависимость из текста описания ("требует X", "после Y", "зависит от Z")
- Используй ТОЛЬКО id из входных данных, не изобретай новые
- Если неявных зависимостей нет — верни пустой массив
PROMPT;

    public function analyze(Collection $issues, array $explicitBlockerPairs): array
    {
        if ($issues->isEmpty()) {
            return ['durations' => [], 'implicit_edges' => []];
        }

        $messages = [
            new MessageDTO('system', self::SYSTEM_PROMPT),
            new MessageDTO('user', $this->buildUserMessage($issues, $explicitBlockerPairs)),
        ];

        $model = config('ai.providers.openrouter.models.critical_path', 'google/gemini-3.1-pro-preview');

        try {
            $raw = OpenRouterClient::chat(
                $messages,
                $model,
                8192,
                forceJsonResponse: true,
                extraPayload: [
                    'reasoning' => [
                        'max_tokens' => 256,
                        'exclude' => true,
                    ],
                ],
            );

            return $this->parseResponse($raw, $issues->pluck('id')->all());
        } catch (\Throwable $e) {
            Log::error('CriticalPathLlmAnalyzer: LLM call failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->fallbackDurations($issues);
        }
    }

    private function buildUserMessage(Collection $issues, array $explicitBlockerPairs): string
    {
        $issuesData = $issues->map(fn (Issue $issue) => [
            'id' => $issue->id,
            'name' => $issue->name,
            'description' => $issue->description
                ? mb_substr($issue->description, 0, self::MAX_DESCRIPTION_LENGTH)
                : null,
            'status' => $issue->status,
            'priority' => $issue->priority,
            'priority_label' => $this->priorityLabel($issue->priority),
            'due_date' => $issue->due_date?->format('Y-m-d'),
        ])->values()->all();

        return json_encode([
            'today' => now()->format('Y-m-d'),
            'explicit_blockers' => $explicitBlockerPairs,
            'issues' => $issuesData,
        ], JSON_UNESCAPED_UNICODE);
    }

    private function parseResponse(string $raw, array $validIssueIds): array
    {
        $data = json_decode($raw, true);

        if (! is_array($data)) {
            Log::warning('CriticalPathLlmAnalyzer: invalid JSON response');

            return ['durations' => [], 'implicit_edges' => []];
        }

        $durations = [];
        foreach ($data['durations'] ?? [] as $issueId => $days) {
            $id = (int) $issueId;
            if (in_array($id, $validIssueIds, true)) {
                $durations[$id] = max(0.5, (float) $days);
            }
        }

        $implicitEdges = [];
        foreach ($data['implicit_edges'] ?? [] as $edge) {
            $from = (int) ($edge['from'] ?? 0);
            $to = (int) ($edge['to'] ?? 0);

            if (
                $from !== $to
                && in_array($from, $validIssueIds, true)
                && in_array($to, $validIssueIds, true)
            ) {
                $implicitEdges[] = ['from' => $from, 'to' => $to];
            }
        }

        return [
            'durations' => $durations,
            'implicit_edges' => $implicitEdges,
        ];
    }

    private function fallbackDurations(Collection $issues): array
    {
        $durations = [];
        foreach ($issues as $issue) {
            $durations[$issue->id] = 1.0;
        }

        return ['durations' => $durations, 'implicit_edges' => []];
    }

    private function priorityLabel(int $priority): string
    {
        return match (true) {
            $priority >= 500 => 'CRITICAL',
            $priority >= 100 => 'HIGH',
            $priority >= 0 => 'NORMAL',
            $priority >= -100 => 'LOW',
            default => 'MINIMAL',
        };
    }
}
