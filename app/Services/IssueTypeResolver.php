<?php

namespace App\Services;

use App\Models\Issue;
use App\Models\OrganizationIssueType;
use App\Models\Team;

class IssueTypeResolver
{
    private const DEVELOPMENT_FALLBACK_KEY = 'backend';

    public function resolveForIssue(Issue $issue): ?OrganizationIssueType
    {
        return $this->resolve(
            organizationId: $issue->organization_id ? (int) $issue->organization_id : null,
            teamId: $issue->team_id ? (int) $issue->team_id : null,
            key: $issue->type,
        );
    }

    public function resolve(?int $organizationId, ?int $teamId, ?string $key): ?OrganizationIssueType
    {
        $normalizedKey = $this->normalizeKey($key);
        if ($normalizedKey === null) {
            return $this->resolveDefault($organizationId, $teamId);
        }

        if ($normalizedKey === 'development' || $normalizedKey === 'bug') {
            $normalizedKey = $this->resolveDevelopmentKey($organizationId, $teamId);
        } elseif ($normalizedKey === 'task') {
            $normalizedKey = 'organization';
        }

        return $this->findType($organizationId, $normalizedKey)
            ?? $this->findType(null, $normalizedKey)
            ?? $this->resolveDefault($organizationId, $teamId);
    }

    public function normalizeKey(?string $key): ?string
    {
        $key = trim((string) $key);

        if ($key === '') {
            return null;
        }

        $key = mb_strtolower($key);

        return match ($key) {
            'frontend', 'backend', 'organization', 'development', 'bug', 'task' => $key,
            default => $key,
        };
    }

    public function resolveDevelopmentKey(?int $organizationId, ?int $teamId): string
    {
        $team = $teamId ? Team::query()->find($teamId) : null;
        $haystack = mb_strtolower(trim((string) (($team?->slug ?? '').' '.($team?->name ?? ''))));

        if ($haystack !== '' && (str_contains($haystack, 'front') || str_contains($haystack, 'фронт'))) {
            return 'frontend';
        }

        if ($haystack !== '' && (str_contains($haystack, 'back') || str_contains($haystack, 'бэкенд') || str_contains($haystack, 'backend'))) {
            return 'backend';
        }

        return self::DEVELOPMENT_FALLBACK_KEY;
    }

    public function resolveDefault(?int $organizationId, ?int $teamId): ?OrganizationIssueType
    {
        $key = $this->resolveDevelopmentKey($organizationId, $teamId);

        return $this->findType($organizationId, $key)
            ?? $this->findType(null, $key)
            ?? $this->findType($organizationId, 'organization')
            ?? $this->findType(null, 'organization');
    }

    public function findType(?int $organizationId, string $key): ?OrganizationIssueType
    {
        $query = OrganizationIssueType::query()->where('key', $key);

        if ($organizationId === null) {
            $query->whereNull('organization_id');
        } else {
            $query->where(function ($builder) use ($organizationId): void {
                $builder->where('organization_id', $organizationId)
                    ->orWhereNull('organization_id');
            })->orderByRaw('CASE WHEN organization_id = ? THEN 0 ELSE 1 END', [$organizationId]);
        }

        return $query->where('is_active', true)->first();
    }
}
