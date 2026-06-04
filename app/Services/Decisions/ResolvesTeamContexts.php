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
            return $this->teamContextsForOrganization($organizationId, $userIds);
        }

        // Backward compat: no organization_id on source — use participant-based discovery.
        if ($userIds->isEmpty()) {
            return [[null, null]];
        }

        // Real (non-default) teams the participants belong to, across any org.
        $teams = \App\Models\Team::where('is_default', false)
            ->whereHas('users', fn ($q) => $q->whereIn('users.id', $userIds))
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
            return $this->teamContextsForOrganization($orgId, $userIds);
        }

        return [[null, null]];
    }

    /**
     * Team/organization pairs for a known organization.
     *
     * Returns the real (non-default) teams whose members include a participant.
     * The default "General" team contains every org member, so it would always
     * match and write a duplicate decision/key-point per row — it is therefore
     * excluded from the primary result and used only as a fallback when no real
     * team matches. Mirrors the is_default guard in CalendarEventOrganizationResolver
     * and UserTeamsResolver::forPipelineTrigger.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $userIds
     * @return array<int, array{0: int|null, 1: int|null}>
     */
    private function teamContextsForOrganization(int $organizationId, \Illuminate\Support\Collection $userIds): array
    {
        $teams = \App\Models\Team::where('organization_id', $organizationId)
            ->where('is_default', false)
            ->when(
                $userIds->isNotEmpty(),
                fn ($q) => $q->whereHas('users', fn ($q) => $q->whereIn('users.id', $userIds)),
            )
            ->get(['id', 'organization_id']);

        if ($teams->isNotEmpty()) {
            return $teams->map(fn ($t) => [$t->id, $t->organization_id])->all();
        }

        // Fallback: the org's default "General" team (contains all members), else org-only.
        $default = \App\Models\Team::where('organization_id', $organizationId)
            ->where('is_default', true)
            ->first(['id', 'organization_id']);

        if ($default) {
            return [[$default->id, $default->organization_id]];
        }

        return [[null, $organizationId]];
    }
}
