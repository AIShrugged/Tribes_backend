<?php

namespace App\Services;

use App\Models\AgentMemory;
use App\Models\AgentProfile;
use App\Models\AgentTask;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AgentMemoryLookupService
{
    public function __construct(
        private readonly AgentMemoryIngestionService $memoryIngestionService,
    ) {}

    public function accessibleMemoriesQuery(int $userId): Builder
    {
        return AgentMemory::query()->whereHas('profile.tasks', function (Builder $query) use ($userId): void {
            $query->where('user_id', $userId);
        });
    }

    public function findAccessibleProfile(int $userId, int $profileId): AgentProfile
    {
        return AgentProfile::query()
            ->whereKey($profileId)
            ->whereHas('tasks', function (Builder $query) use ($userId): void {
                $query->where('user_id', $userId);
            })
            ->firstOrFail();
    }

    public function taskRelevantMemoriesQuery(AgentTask $task): Builder
    {
        $task->loadMissing('profile');

        $profile = $task->profile;
        $repositoryScopeKey = $this->memoryIngestionService->deriveRepositoryScopeKey($task);

        return AgentMemory::query()
            ->where('agent_profile_id', $profile->id)
            ->where('active', true)
            ->where(function (Builder $query) use ($task, $repositoryScopeKey): void {
                $query->where(function (Builder $sub): void {
                    $sub->where('scope_type', 'profile')->whereNull('scope_key');
                })->orWhere(function (Builder $sub) use ($repositoryScopeKey): void {
                    if ($repositoryScopeKey !== null) {
                        $sub->where('scope_type', 'repository')->where('scope_key', $repositoryScopeKey);
                    }
                })->orWhere(function (Builder $sub) use ($task): void {
                    $sub->where('scope_type', 'task')->where('scope_key', (string) $task->id);
                });
            });
    }

    public function searchAccessibleMemories(int $userId, array $filters = [], int $limit = 10): Collection
    {
        $query = $this->accessibleMemoriesQuery($userId)
            ->with('profile')
            ->where('active', true);

        if (! empty($filters['task_id'])) {
            $task = AgentTask::query()
                ->with('profile')
                ->where('user_id', $userId)
                ->findOrFail((int) $filters['task_id']);

            $query = $this->taskRelevantMemoriesQuery($task)->with('profile');
        }

        if (! empty($filters['agent_profile_id'])) {
            $query->where('agent_profile_id', (int) $filters['agent_profile_id']);
        }

        if (! empty($filters['profile_key'])) {
            $profileKey = trim((string) $filters['profile_key']);
            $query->whereHas('profile', function (Builder $builder) use ($profileKey): void {
                $builder->where('key', $profileKey);
            });
        }

        foreach (['scope_type', 'scope_key', 'kind'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, trim((string) $filters[$field]));
            }
        }

        $repositoryScopeKey = $this->buildRepositoryScopeKey(
            $filters['provider'] ?? null,
            $filters['owner'] ?? null,
            $filters['repo'] ?? null,
        );

        if ($repositoryScopeKey !== null) {
            $query->where('scope_type', 'repository')
                ->where('scope_key', $repositoryScopeKey);
        }

        $search = trim((string) ($filters['query'] ?? ''));
        if ($search !== '') {
            $escaped = addcslashes(mb_strtolower($search), '%_\\');
            $query->where(function (Builder $builder) use ($escaped): void {
                $builder->whereRaw('LOWER(content) LIKE ?', ["%{$escaped}%"])
                    ->orWhereRaw('LOWER(kind) LIKE ?', ["%{$escaped}%"])
                    ->orWhereRaw('LOWER(COALESCE(scope_key, \'\')) LIKE ?', ["%{$escaped}%"]);
            });
        }

        return $query
            ->orderByDesc('priority')
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->limit(max(1, min(20, $limit)))
            ->get();
    }

    private function buildRepositoryScopeKey(mixed $provider, mixed $owner, mixed $repo): ?string
    {
        $provider = trim((string) ($provider ?? ''));
        $owner = trim((string) ($owner ?? ''));
        $repo = trim((string) ($repo ?? ''));

        if ($provider === '' || $owner === '' || $repo === '') {
            return null;
        }

        return strtolower("{$provider}:{$owner}/{$repo}");
    }
}
