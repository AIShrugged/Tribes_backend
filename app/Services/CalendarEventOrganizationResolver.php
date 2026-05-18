<?php

namespace App\Services;

use App\Models\CalendarEvent;
use App\Models\Team;
use App\Models\User;

class CalendarEventOrganizationResolver
{
    /**
     * Resolve the best-matching team for a calendar event.
     *
     * Uses source->organization_id as the authoritative org binding.
     * Within that org, picks the team with the most matching participants.
     * Falls back to old heuristic when organization_id is not set (backward compat).
     *
     * @return array{team: Team, user: User}|null
     */
    public function resolve(CalendarEvent $event): ?array
    {
        $user = $this->resolveOwner($event);

        if (! $user) {
            return null;
        }

        $organizationId = $event->source?->organization_id;

        if (! $organizationId) {
            $team = $user->teams()->first();

            return $team ? ['team' => $team, 'user' => $user] : null;
        }

        $participantUserIds = $this->getParticipantUserIds($event);

        $team = $participantUserIds->isNotEmpty()
            ? Team::where('organization_id', $organizationId)
                ->whereHas('users', fn ($q) => $q->whereIn('users.id', $participantUserIds))
                ->withCount(['users' => fn ($q) => $q->whereIn('users.id', $participantUserIds)])
                ->orderByDesc('users_count')
                ->first()
            : null;

        $team ??= $user->teams()->where('organization_id', $organizationId)->first();

        return $team ? ['team' => $team, 'user' => $user] : null;
    }

    private function resolveOwner(CalendarEvent $event): ?User
    {
        if ($event->creator) {
            return $event->creator;
        }

        return $event->source?->user;
    }

    private function getParticipantUserIds(CalendarEvent $event): \Illuminate\Support\Collection
    {
        return $event->participants()
            ->whereNotNull('profile_id')
            ->with('profile')
            ->get()
            ->pluck('profile.user_id')
            ->filter()
            ->unique()
            ->values();
    }
}
