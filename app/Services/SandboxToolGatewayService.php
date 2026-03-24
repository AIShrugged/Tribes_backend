<?php

namespace App\Services;

use App\Models\AgentTaskRun;
use Illuminate\Support\Arr;

class SandboxToolGatewayService
{
    private const AUTO_IDEMPOTENT_TOOLS = [
        'create_agent_task',
        'update_agent_task',
        'create_workspace',
        'create_workspace_directory',
        'write_workspace_file',
        'delete_workspace_file',
        'copy_workspace_file',
        'move_workspace_file',
        'delete_workspace',
        'create_followup_agent_task',
        'regenerate_followup',
    ];

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

        $toolCalls = Arr::wrap(data_get($run->metadata, 'tool_calls', []));
        $arguments = $arguments ?? [];
        $idempotencyKey = $this->resolveIdempotencyKey($toolName, $arguments);

        if ($idempotencyKey !== null) {
            foreach ($toolCalls as $toolCall) {
                if (($toolCall['tool_name'] ?? null) !== $toolName) {
                    continue;
                }

                if (($toolCall['idempotency_key'] ?? null) !== $idempotencyKey) {
                    continue;
                }

                return [
                    'success' => true,
                    'result' => $toolCall['result'] ?? [
                        'success' => false,
                        'error' => 'Idempotent tool call replayed without stored result.',
                    ],
                    'replayed' => true,
                ];
            }
        }

        $workspace = storage_path('app/private/sandbox-runs/'.$run->id);
        $result = $this->toolExecutor->execute($task, $user, $toolName, $arguments, $workspace, $run);

        $run->refresh();
        $toolCalls = Arr::wrap(data_get($run->metadata, 'tool_calls', []));
        $toolCalls[] = [
            'tool_name' => $toolName,
            'arguments' => $arguments,
            'idempotency_key' => $idempotencyKey,
            'called_at' => now()->toIso8601String(),
            'success' => (bool) ($result['success'] ?? true),
            'result' => $result,
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

    private function resolveIdempotencyKey(string $toolName, array $arguments): ?string
    {
        $explicitKey = $arguments['idempotency_key'] ?? null;
        if (is_string($explicitKey) && trim($explicitKey) !== '') {
            return trim($explicitKey);
        }

        if (! in_array($toolName, self::AUTO_IDEMPOTENT_TOOLS, true)) {
            return null;
        }

        return sha1($toolName.':'.json_encode($this->normalizeArgumentsForFingerprint($arguments)));
    }

    private function normalizeArgumentsForFingerprint(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalizeArgumentsForFingerprint($item), $value);
        }

        ksort($value);

        $normalized = [];
        foreach ($value as $key => $item) {
            if ($key === 'idempotency_key') {
                continue;
            }

            $normalized[$key] = $this->normalizeArgumentsForFingerprint($item);
        }

        return $normalized;
    }
}
