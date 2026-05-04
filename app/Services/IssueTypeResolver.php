<?php

namespace App\Services;

use App\Models\Issue;
use App\Models\OrganizationIssueType;

class IssueTypeResolver
{
    public function resolveForIssue(Issue $issue): ?OrganizationIssueType
    {
        return $this->resolve(
            organizationId: $issue->organization_id ? (int) $issue->organization_id : null,
            key: $issue->type,
        );
    }

    /**
     * @param  int|null  $organizationId
     * @param  int|string|null  $teamIdOrKey  Accepts key directly; legacy $teamId param is ignored.
     * @param  string|null  $key  Issue type key (when called with 3 args for backward compat).
     */
    public function resolve(?int $organizationId, int|string|null $teamIdOrKey = null, ?string $key = null): ?OrganizationIssueType
    {
        // Backward compat: resolve($orgId, $teamId, $key) — ignore $teamId
        $resolvedKey = $key ?? (is_string($teamIdOrKey) ? $teamIdOrKey : null);

        $normalizedKey = $this->normalizeKey($resolvedKey);
        if ($normalizedKey === null) {
            return $this->resolveDefault($organizationId);
        }

        return $this->findType($organizationId, $normalizedKey)
            ?? $this->findType(null, $normalizedKey)
            ?? $this->resolveDefault($organizationId);
    }

    public function normalizeKey(?string $key): ?string
    {
        $key = trim((string) $key);

        if ($key === '') {
            return null;
        }

        $key = mb_strtolower($key);

        return match ($key) {
            'development', 'frontend', 'backend', 'bug' => 'development',
            'organization', 'task' => 'organization',
            'epic' => 'epic',
            default => $key,
        };
    }

    public function resolveDefault(?int $organizationId): ?OrganizationIssueType
    {
        return $this->findType($organizationId, 'development')
            ?? $this->findType(null, 'development')
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
