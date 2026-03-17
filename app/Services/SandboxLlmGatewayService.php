<?php

namespace App\Services;

use App\Enums\AgentTaskType;
use App\Models\AgentTaskRun;
use App\Services\Agent\AgentModelRouter;
use Illuminate\Support\Arr;

class SandboxLlmGatewayService
{
    public function __construct(
        private readonly AgentTaskRunTokenService $runTokenService,
        private readonly AgentTaskToolExecutor $toolExecutor,
        private readonly AgentModelRouter $modelRouter,
    ) {}

    public function executeCompletion(
        AgentTaskRun $run,
        string $plainToken,
        array $messages,
        ?string $systemPrompt = null,
        ?int $maxTokens = null,
        bool $includeTools = true,
    ): array {
        $task = $run->task()->first();
        $user = $task?->user()->first();

        if (! $task || ! $user) {
            throw new \RuntimeException('Agent task context not found');
        }

        if (! $task->isIsolated()) {
            throw new \RuntimeException('Sandbox gateway is only available for isolated agent tasks');
        }

        if (! $this->runTokenService->validate($run, $plainToken)) {
            throw new \RuntimeException('Invalid sandbox run token');
        }

        if ($run->finished_at !== null) {
            throw new \RuntimeException('Sandbox run is already finished');
        }

        $tools = $includeTools ? $this->toolExecutor->describeTools($task, $user) : [];
        $model = $this->resolveModel($task);
        $response = OpenRouterClient::chatWithTools(
            $messages,
            $tools === [] ? null : $tools,
            $model,
            max(256, min(4096, (int) ($maxTokens ?? 2048))),
            $systemPrompt
        );

        $llmCalls = Arr::wrap(data_get($run->metadata, 'llm_calls', []));
        $llmCalls[] = [
            'model' => $model,
            'messages_count' => count($messages),
            'tools_count' => count($tools),
            'include_tools' => $includeTools,
            'called_at' => now()->toIso8601String(),
            'finish_reason' => $response['choices'][0]['finish_reason'] ?? null,
        ];

        $run->update([
            'metadata' => [
                ...($run->metadata ?? []),
                'llm_calls' => $llmCalls,
            ],
        ]);

        return [
            'success' => true,
            'model' => $model,
            'message' => $response['choices'][0]['message'] ?? null,
            'finish_reason' => $response['choices'][0]['finish_reason'] ?? null,
        ];
    }

    private function resolveModel($task): string
    {
        $taskType = AgentTaskType::tryFrom((string) ($task->agent_task_type ?? ''))
            ?? AgentTaskType::BACKGROUND;

        return $this->modelRouter->resolve($taskType);
    }
}
