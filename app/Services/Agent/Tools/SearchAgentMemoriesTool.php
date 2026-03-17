<?php

namespace App\Services\Agent\Tools;

use App\Models\User;
use App\Services\AgentMemoryLookupService;

class SearchAgentMemoriesTool extends AbstractAgentTool
{
    public function __construct(
        private readonly User $user,
        private readonly AgentMemoryLookupService $memoryLookupService,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'search_agent_memories';
    }

    public function getDescription(): string
    {
        return 'Search persistent memories collected by your configured agent profiles and tasks. Use this when the user asks what the system already knows about a repository, architecture, prior agent findings, or saved facts.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'profile_key' => [
                    'type' => 'string',
                    'description' => 'Optional agent profile key, for example github-reviewer.',
                ],
                'task_id' => [
                    'type' => 'integer',
                    'description' => 'Optional agent task id owned by the current user.',
                ],
                'provider' => [
                    'type' => 'string',
                    'description' => 'Repository provider, for example github.',
                ],
                'owner' => [
                    'type' => 'string',
                    'description' => 'Repository owner or organization.',
                ],
                'repo' => [
                    'type' => 'string',
                    'description' => 'Repository name.',
                ],
                'scope_type' => [
                    'type' => 'string',
                    'description' => 'Optional memory scope filter: profile, repository, or task.',
                    'enum' => ['profile', 'repository', 'task'],
                ],
                'scope_key' => [
                    'type' => 'string',
                    'description' => 'Optional exact scope key filter.',
                ],
                'kind' => [
                    'type' => 'string',
                    'description' => 'Optional memory kind filter, for example architecture_fact.',
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'Optional free-text search within saved memory content.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of memories to return, 1-20.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $parameters = $parameters ?? [];
        $limit = (int) ($parameters['limit'] ?? 10);

        try {
            $memories = $this->memoryLookupService->searchAccessibleMemories($this->user->id, $parameters, $limit);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }

        return [
            'success' => true,
            'count' => $memories->count(),
            'memories' => $memories->map(fn ($memory) => [
                'id' => $memory->id,
                'profile' => [
                    'id' => $memory->profile?->id,
                    'key' => $memory->profile?->key,
                    'name' => $memory->profile?->name,
                ],
                'scope_type' => $memory->scope_type,
                'scope_key' => $memory->scope_key,
                'kind' => $memory->kind,
                'content' => $memory->content,
                'priority' => $memory->priority,
                'last_seen_at' => $memory->last_seen_at?->toISOString(),
                'source_task_id' => $memory->metadata['source_task_id'] ?? null,
                'source_run_id' => $memory->metadata['source_run_id'] ?? null,
            ])->values()->all(),
        ];
    }
}
