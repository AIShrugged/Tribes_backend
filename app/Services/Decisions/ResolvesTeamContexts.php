<?php

namespace App\Services\Decisions;

use App\Models\CalendarEvent;

/**
 * Shared logic for resolving which team/organization pairs a calendar event
 * belongs to, used by both ExtractDecisionsService and ExtractKeyPointsService.
 *
 * @return array<int, array{0: int|null, 1: int|null}>
 */
trait ResolvesTeamContexts
{
    /**
     * Derive team/organization pairs from the calendar event.
     *
     * Uses source->organization_id as the authoritative org, then finds all teams
     * within that org where participants are members. Falls back to participant-based
     * org discovery when organization_id is not set (backward compat with old sources).
     *
     * @return array<int, array{0: int|null, 1: int|null}>
     */
    private function resolveTeamContexts(CalendarEvent $event): array
    {
        $participantUserIds = $event->participants()
            ->with('profile.user')
            ->get()
            ->map(fn ($p) => optional($p->profile)->user_id)
            ->filter()
            ->values();

        $creatorId = $event->creator_user_id;
        $userIds = $participantUserIds
            ->when($creatorId, fn ($c) => $c->push($creatorId))
            ->unique()
            ->values();

        $organizationId = $event->source?->organization_id;

        if ($organizationId) {
            $teams = \App\Models\Team::where('organization_id', $organizationId)
                ->when(
                    $userIds->isNotEmpty(),
                    fn ($q) => $q->whereHas('users', fn ($q) => $q->whereIn('users.id', $userIds)),
                )
                ->get(['id', 'organization_id']);

            if ($teams->isNotEmpty()) {
                return $teams->map(fn ($t) => [$t->id, $t->organization_id])->all();
            }

            // No participant-matched teams in org — return all teams in the org
            $teamsInOrg = \App\Models\Team::where('organization_id', $organizationId)
                ->get(['id', 'organization_id']);

            if ($teamsInOrg->isNotEmpty()) {
                return $teamsInOrg->map(fn ($t) => [$t->id, $t->organization_id])->all();
            }

            return [[null, $organizationId]];
        }

        // Backward compat: no organization_id on source — use participant-based discovery
        if ($userIds->isEmpty()) {
            return [[null, null]];
        }

        $teams = \App\Models\Team::whereHas('users', fn ($q) => $q->whereIn('users.id', $userIds))
            ->get(['id', 'organization_id']);

        if ($teams->isNotEmpty()) {
            return $teams->map(fn ($team) => [$team->id, $team->organization_id])->all();
        }

        $orgId = \App\Models\User::whereIn('id', $userIds)
            ->with('organizations')
            ->get()
            ->flatMap(fn ($u) => $u->organizations->pluck('id'))
            ->first();

        if ($orgId) {
            $teamsInOrg = \App\Models\Team::where('organization_id', $orgId)
                ->get(['id', 'organization_id']);

            if ($teamsInOrg->isNotEmpty()) {
                return $teamsInOrg->map(fn ($t) => [$t->id, $t->organization_id])->all();
            }
        }

        return [[null, $orgId ?? null]];
    }
}
