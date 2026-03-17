<?php

namespace App\Services;

use App\Models\AgentMemory;
use App\Models\AgentTask;
use App\Models\AgentTaskRun;

class AgentMemoryIngestionService
{
    public function ingest(AgentTask $task, AgentTaskRun $run, array $memoryCandidates): array
    {
        $task->loadMissing('profile');

        if (! $task->profile || $memoryCandidates === []) {
            return [];
        }

        $ingested = [];

        foreach ($memoryCandidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $content = trim((string) ($candidate['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            $scopeType = $this->resolveScopeType($candidate, $task);
            $scopeKey = $this->resolveScopeKey($candidate, $task, $scopeType);
            $kind = trim((string) ($candidate['kind'] ?? 'fact')) ?: 'fact';
            $priority = max(0, min(100, (int) ($candidate['priority'] ?? 50)));

            $memory = AgentMemory::query()->updateOrCreate(
                [
                    'agent_profile_id' => $task->profile->id,
                    'scope_type' => $scopeType,
                    'scope_key' => $scopeKey,
                    'kind' => $kind,
                    'content' => $content,
                ],
                [
                    'priority' => $priority,
                    'active' => true,
                    'last_seen_at' => now(),
                    'metadata' => [
                        ...($candidate['metadata'] ?? []),
                        'source_task_id' => $task->id,
                        'source_run_id' => $run->id,
                    ],
                ],
            );

            $ingested[] = [
                'id' => $memory->id,
                'scope_type' => $memory->scope_type,
                'scope_key' => $memory->scope_key,
                'kind' => $memory->kind,
                'content' => $memory->content,
            ];
        }

        return $ingested;
    }

    private function resolveScopeType(array $candidate, AgentTask $task): string
    {
        $scopeType = trim((string) ($candidate['scope_type'] ?? ''));
        if ($scopeType !== '') {
            return $scopeType;
        }

        return $this->deriveRepositoryScopeKey($task) ? 'repository' : 'profile';
    }

    private function resolveScopeKey(array $candidate, AgentTask $task, string $scopeType): ?string
    {
        $scopeKey = $candidate['scope_key'] ?? null;
        if (is_string($scopeKey) && $scopeKey !== '') {
            return $scopeKey;
        }

        if ($scopeType === 'repository') {
            return $this->deriveRepositoryScopeKey($task);
        }

        if ($scopeType === 'task') {
            return (string) $task->id;
        }

        return null;
    }

    public function deriveRepositoryScopeKey(AgentTask $task): ?string
    {
        $payload = $task->input_payload ?? [];
        if (! is_array($payload)) {
            return null;
        }

        $provider = trim((string) ($payload['provider'] ?? ''));
        $owner = trim((string) ($payload['owner'] ?? ''));
        $repo = trim((string) ($payload['repo'] ?? ''));

        if ($provider === '' || $owner === '' || $repo === '') {
            return null;
        }

        return strtolower("{$provider}:{$owner}/{$repo}");
    }
}
