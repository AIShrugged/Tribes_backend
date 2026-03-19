<?php

namespace App\Services;

use App\Models\AgentMemory;
use App\Models\AgentTask;
use Illuminate\Support\Collection;

class AgentTaskContextBuilder
{
    public function __construct(
        private readonly AgentMemoryIngestionService $memoryIngestionService,
    ) {}

    public function build(AgentTask $task): array
    {
        $task->loadMissing('profile', 'parentTask');

        $profile = $task->profile;
        $memories = $this->resolveMemories($task);
        $followupContext = $this->buildFollowupContext($task);

        $profilePrompt = trim((string) ($profile?->system_prompt ?? ''));
        $memoryPrompt = $this->renderMemoryPrompt($memories);
        $taskPayloadPrompt = $this->renderTaskPayloadPrompt($task);

        $systemPromptExtension = trim(implode("\n\n", array_filter([
            $profilePrompt !== '' ? "## Agent Profile\n\n{$profilePrompt}" : null,
            $memoryPrompt,
        ])));

        $userPrompt = trim(implode("\n\n", array_filter([
            $task->prompt,
            $taskPayloadPrompt,
        ])));

        return [
            'profile' => $profile,
            'memories' => $memories->map(fn (AgentMemory $memory) => [
                'id' => $memory->id,
                'scope_type' => $memory->scope_type,
                'scope_key' => $memory->scope_key,
                'kind' => $memory->kind,
                'priority' => $memory->priority,
                'content' => $memory->content,
            ])->values()->all(),
            'system_prompt_extension' => $systemPromptExtension,
            'user_prompt' => $userPrompt,
            'allowed_tools' => $task->effectiveAllowedTools(),
            'allowed_outbound_hosts' => $task->effectiveAllowedOutboundHosts(),
            'execution_mode' => $task->effectiveExecutionMode()->value,
            'sandbox_profile' => $task->effectiveSandboxProfile(),
            'input_payload' => $task->input_payload ?? [],
            'followup_policy' => [
                'max_depth' => (int) config('agent.agent_tasks.followups.max_depth', 5),
                'max_per_run' => (int) config('agent.agent_tasks.followups.max_per_run', 10),
                'max_delay_seconds' => (int) config('agent.agent_tasks.followups.max_delay_seconds', 86400),
                'prefer_small_bounded_tasks' => true,
                'preferred_outcomes' => [
                    'complete one concrete deliverable inside the current run',
                    'create a narrow follow-up task when the next step is separate, delayed, or would bloat context',
                    'handoff reminders, PR creation, and deferred external actions as separate follow-up tasks when appropriate',
                ],
            ],
            'task_lineage' => [
                'task_id' => $task->id,
                'organization_id' => $task->organization_id,
                'team_id' => $task->team_id,
                'parent_task_id' => $task->parent_agent_task_id,
                'origin_run_id' => $task->origin_agent_task_run_id,
                'followup_depth' => (int) ($task->followup_depth ?? 0),
                'inherited_context_summary' => $followupContext['context_summary'],
            ],
        ];
    }

    private function resolveMemories(AgentTask $task)
    {
        $profile = $task->profile;
        if (! $profile) {
            return collect();
        }

        $repositoryScopeKey = $this->memoryIngestionService->deriveRepositoryScopeKey($task);

        $memories = $profile->memories()
            ->where('active', true)
            ->where('kind', '!=', 'artifact_fact')
            ->where(function ($query) use ($task, $repositoryScopeKey): void {
                $query->where(function ($sub): void {
                    $sub->where('scope_type', 'profile')->whereNull('scope_key');
                })->orWhere(function ($sub) use ($repositoryScopeKey): void {
                    if ($repositoryScopeKey !== null) {
                        $sub->where('scope_type', 'repository')->where('scope_key', $repositoryScopeKey);
                    } else {
                        $sub->whereRaw('1 = 0');
                    }
                })->orWhere(function ($sub) use ($task): void {
                    $sub->where('scope_type', 'task')->where('scope_key', (string) $task->id);
                });
            })
            ->orderByDesc('priority')
            ->orderByDesc('updated_at')
            ->orderBy('id')
            ->get();

        return $this->deduplicateMemories($memories);
    }

    private function renderMemoryPrompt($memories): ?string
    {
        if ($memories->isEmpty()) {
            return null;
        }

        $lines = $memories->map(function (AgentMemory $memory): string {
            $scope = $memory->scope_type.($memory->scope_key ? ':'.$memory->scope_key : '');

            return "- [{$memory->kind}] ({$scope}) {$memory->content}";
        })->implode("\n");

        return "## Agent Memory\n\nUse these persistent instructions and facts while executing this task:\n{$lines}";
    }

    private function renderTaskPayloadPrompt(AgentTask $task): ?string
    {
        $payload = $task->input_payload ?? [];
        if (! is_array($payload) || $payload === []) {
            return null;
        }

        return "## Task Payload\n\n```json\n".json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n```";
    }

    private function buildFollowupContext(AgentTask $task): array
    {
        $metadata = $task->metadata ?? [];
        $followupMetadata = is_array($metadata['followup'] ?? null) ? $metadata['followup'] : [];
        $contextSummary = trim((string) ($followupMetadata['context_summary'] ?? ''));

        return [
            'context_summary' => $contextSummary !== '' ? $contextSummary : null,
            'created_from_task_id' => $followupMetadata['created_from_task_id'] ?? $task->parent_agent_task_id,
            'created_from_run_id' => $followupMetadata['created_from_run_id'] ?? $task->origin_agent_task_run_id,
            'parent_task_name' => $task->parentTask?->name,
        ];
    }

    private function deduplicateMemories(Collection $memories): Collection
    {
        $seen = [];

        return $memories->filter(function (AgentMemory $memory) use (&$seen): bool {
            $signature = $this->memorySignature($memory);

            if (isset($seen[$signature])) {
                return false;
            }

            $seen[$signature] = true;

            return true;
        })->values();
    }

    private function memorySignature(AgentMemory $memory): string
    {
        $kind = (string) $memory->kind;
        $scopeType = (string) $memory->scope_type;
        $scopeKey = (string) ($memory->scope_key ?? '');
        $content = trim((string) $memory->content);

        $normalizedContent = match ($kind) {
            'test_fact' => $this->normalizeTestFact($content),
            default => mb_strtolower(trim(preg_replace('/\s+/', ' ', $content) ?? $content)),
        };

        return implode('|', [$scopeType, $scopeKey, $kind, $normalizedContent]);
    }

    private function normalizeTestFact(string $content): string
    {
        $patterns = [
            '/^test command failed:\s*(.+?)\s*\(exit_code=(\d+)\)\.?$/i',
            '/^test command failed\s*\(exit_code=(\d+)\):\s*(.+?)\.?$/i',
            '/^test command succeeded:\s*(.+?)\s*\(exit_code=(\d+)\)\.?$/i',
            '/^test command succeeded\s*\(exit_code=(\d+)\):\s*(.+?)\.?$/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content, $matches) === 1) {
                if (str_contains($pattern, 'failed')) {
                    [$command, $exitCode] = isset($matches[2]) && str_contains($pattern, 'failed:\s*')
                        ? [$matches[1], $matches[2]]
                        : [$matches[2], $matches[1]];

                    return 'test-failed|'.mb_strtolower(trim($command)).'|'.$exitCode;
                }

                [$command, $exitCode] = isset($matches[2]) && str_contains($pattern, 'succeeded:\s*')
                    ? [$matches[1], $matches[2]]
                    : [$matches[2], $matches[1]];

                return 'test-succeeded|'.mb_strtolower(trim($command)).'|'.$exitCode;
            }
        }

        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $content) ?? $content));
    }
}
