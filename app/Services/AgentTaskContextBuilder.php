<?php

namespace App\Services;

use App\Models\AgentMemory;
use App\Models\AgentTask;

class AgentTaskContextBuilder
{
    public function __construct(
        private readonly AgentMemoryIngestionService $memoryIngestionService,
    ) {}

    public function build(AgentTask $task): array
    {
        $task->loadMissing('profile');

        $profile = $task->profile;
        $memories = $this->resolveMemories($task);

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
        ];
    }

    private function resolveMemories(AgentTask $task)
    {
        $profile = $task->profile;
        if (! $profile) {
            return collect();
        }

        $repositoryScopeKey = $this->memoryIngestionService->deriveRepositoryScopeKey($task);

        return $profile->memories()
            ->where('active', true)
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
            ->orderBy('id')
            ->get();
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
}
