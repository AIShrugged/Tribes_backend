<?php

namespace App\Services\Issue;

use App\Models\Issue;
use App\Models\Organization;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

/**
 * Allocates the per-organization sequential number and display code (e.g. DEV-14)
 * for an issue at creation time.
 *
 * The next number is drawn atomically from organizations.last_issue_number under a
 * row lock, so concurrent issue creation in the same organization never collides.
 * Issues without an owning organization (neither organization_id nor a team that
 * belongs to an organization) get no code — they are personal/unscoped tasks.
 */
class IssueCodeAllocator
{
    public function allocate(Issue $issue): void
    {
        // Respect an explicitly-provided code (e.g. data migrations) and skip re-allocation.
        if (filled($issue->code)) {
            return;
        }

        $organizationId = $this->resolveOrganizationId($issue);
        if (! $organizationId) {
            return;
        }

        [$number, $organizationCode] = $this->nextNumber($organizationId);
        if ($number === null) {
            return;
        }

        $issue->number = $number;
        $issue->code = $organizationCode.'-'.$number;
    }

    /**
     * @return array{0: int|null, 1: string|null} [number, organization code]
     */
    private function nextNumber(int $organizationId): array
    {
        return DB::transaction(function () use ($organizationId): array {
            $organization = Organization::query()
                ->whereKey($organizationId)
                ->lockForUpdate()
                ->first(['id', 'code', 'last_issue_number']);

            if (! $organization || blank($organization->code)) {
                return [null, null];
            }

            $number = (int) $organization->last_issue_number + 1;

            Organization::query()
                ->whereKey($organizationId)
                ->update(['last_issue_number' => $number]);

            return [$number, $organization->code];
        });
    }

    private function resolveOrganizationId(Issue $issue): ?int
    {
        if ($issue->organization_id) {
            return (int) $issue->organization_id;
        }

        if ($issue->team_id) {
            $organizationId = $issue->team?->organization_id
                ?? Team::query()->whereKey($issue->team_id)->value('organization_id');

            return $organizationId ? (int) $organizationId : null;
        }

        return null;
    }
}
