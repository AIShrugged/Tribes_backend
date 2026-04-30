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
     * Derive team/organization pairs from all participants of the calendar event.
     * Collects every registered User reachable via Participant → Profile → User,
     * merges in the event creator, then unions all their team memberships so the
     * record is visible in every relevant team's log.
     *
     * Falls back to [(null, orgId)] when no participant belongs to any team but
     * an organization can be determined, or to [(null, null)] as a last resort.
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

        if ($userIds->isEmpty()) {
            return [[null, null]];
        }

        $teams = \App\Models\Team::whereHas('users', fn ($q) => $q->whereIn('users.id', $userIds))
            ->get(['id', 'organization_id']);

        if ($teams->isNotEmpty()) {
            return $teams->map(fn ($team) => [$team->id, $team->organization_id])->all();
        }

        // No team found — try to resolve at organization level.
        $orgId = \App\Models\User::whereIn('id', $userIds)
            ->with('organizations')
            ->get()
            ->flatMap(fn ($u) => $u->organizations->pluck('id'))
            ->first();

        if ($orgId) {
            // Assign to every team in the org so the decision is visible in team views.
            $teamsInOrg = \App\Models\Team::where('organization_id', $orgId)
                ->get(['id', 'organization_id']);

            if ($teamsInOrg->isNotEmpty()) {
                return $teamsInOrg->map(fn ($t) => [$t->id, $t->organization_id])->all();
            }
        }

        return [[null, $orgId ?? null]];
    }
}