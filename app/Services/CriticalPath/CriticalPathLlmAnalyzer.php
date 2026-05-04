<?php

namespace App\Services\CriticalPath;

use App\Domain\DTO\AI\MessageDTO;
use App\Models\Issue;
use App\Services\OpenRouterClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class CriticalPathLlmAnalyzer
{
    private const MAX_DESCRIPTION_LENGTH = 800;

    private const SYSTEM_PROMPT = <<<'PROMPT'
You are a project-planning engine. Analyze the issue list and return ONLY valid JSON, with no prose, no Markdown, and no explanations.

Response schema:
{
  "durations": {
    "<issue_id>": <number of work days as a float>
  },
  "implicit_edges": [
    { "from": <id of prerequisite issue>, "to": <id of dependent issue> }
  ]
}

Duration rules, in work days:
- Bug or small configuration fix: 0.5-1
- Simple task with a clear description: 1-2
- Medium-complexity feature: 2-5
- Large feature or refactoring: 5-10
- CRITICAL-priority issue with no description: 3
- Epic (type=epic): 0.5 — represents final review/closure overhead; real work is in child issues
- Do NOT include issues with status done

Epic rules:
- Issues with type=epic are high-level containers; child issues already have explicit_blockers edges pointing to the epic
- Do NOT add implicit_edges from child issues to their parent epic — those are already provided as explicit_blockers
- You MAY add implicit_edges between epics and other epics or non-child issues when there is a clear work-order reason
- An epic's duration represents only the overhead of closing/reviewing the epic itself

Rules for implicit_edges:
- Your job is to build a DAG of work order, not only to search for literal words such as "depends", "after", or "requires"
- Add only dependencies that are NOT already present in explicit_blockers
- Edge {from: A, to: B} means: A must be completed before B can be started or finished properly
- Infer dependencies from titles, descriptions, deliverables, references to other issues (#123), project phases, and practical PM judgment
- Common dependency chains:
  - requirements / discussion / cases / docs -> implementation
  - design / draft / plan -> upload / notify / release
  - collect prompts/docs/tasks -> analyze / create documentation / implement improvements
  - architecture / specification / question aggregation -> implementation / decision lookup / memory work
  - stakeholder meeting / UC review -> write requirements / implement UC
- Do NOT add a dependency only because two issues share a topic; there must be real work order
- Do NOT make PM-comment / recommendation / "solution options" issues prerequisites for their parent issues. Those are comments, not prerequisites
- Use ONLY ids from the input data; never invent ids
- Return an empty array only if no reliable work order can be inferred
- The graph must be acyclic
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

            $result = $this->parseResponse($raw, $issues->pluck('id')->all());

            if ($issues->count() > 1 && empty($explicitBlockerPairs) && empty($result['implicit_edges'])) {
                Log::warning('CriticalPathLlmAnalyzer: no dependencies inferred', [
                    'issue_count' => $issues->count(),
                    'issue_ids' => $issues->pluck('id')->values()->all(),
                ]);
            }

            return $result;
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
            'type' => $issue->type,
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
