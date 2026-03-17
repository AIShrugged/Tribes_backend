<?php

namespace App\Services;

use App\Models\AgentTaskRun;
use Illuminate\Support\Arr;

class SandboxToolGatewayService
{
    public function __construct(
        private readonly AgentTaskRunTokenService $runTokenService,
        private readonly AgentTaskToolExecutor $toolExecutor,
    ) {}

    public function executeToolCall(AgentTaskRun $run, string $plainToken, string $toolName, ?array $arguments = null): array
    {
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

        $result = $this->toolExecutor->execute($task, $user, $toolName, $arguments ?? []);

        $toolCalls = Arr::wrap(data_get($run->metadata, 'tool_calls', []));
        $toolCalls[] = [
            'tool_name' => $toolName,
            'arguments' => $arguments ?? [],
            'called_at' => now()->toIso8601String(),
            'success' => (bool) ($result['success'] ?? true),
        ];

        $run->update([
            'metadata' => [
                ...($run->metadata ?? []),
                'tool_calls' => $toolCalls,
            ],
        ]);

        return [
            'success' => true,
            'result' => $result,
        ];
    }
}
