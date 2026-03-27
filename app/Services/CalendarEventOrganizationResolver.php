<?php

namespace App\Services;

use App\Models\CalendarEvent;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CalendarEventOrganizationResolver
{
    /**
     * Resolve the best-matching team for a calendar event based on its participants.
     *
     * Logic:
     * 1. Collect user IDs from event participants (via profiles)
     * 2. Find the organization where most participants are members
     * 3. Within that org, find the team where most participants belong
     * 4. Fallback to the owner's first team if no participants match
     *
     * @return array{team: Team, user: User}|null
     */
    public function resolve(CalendarEvent $event): ?array
    {
        $user = $this->resolveOwner($event);

        if (! $user) {
            return null;
        }

        $participantUserIds = $this->getParticipantUserIds($event);

        // If no participants matched to system users, fallback to owner's first team
        if ($participantUserIds->isEmpty()) {
            $team = $user->teams()->first();

            return $team ? ['team' => $team, 'user' => $user] : null;
        }

        // Find the organization with the most participants
        $organizationId = DB::table('organization_user')
            ->whereIn('user_id', $participantUserIds)
            ->select('organization_id', DB::raw('count(*) as cnt'))
            ->groupBy('organization_id')
            ->orderByDesc('cnt')
            ->value('organization_id');

        if (! $organizationId) {
            $team = $user->teams()->first();

            return $team ? ['team' => $team, 'user' => $user] : null;
        }

        // Find the team within that org with the most participants
        $team = Team::where('organization_id', $organizationId)
            ->whereHas('users', fn ($q) => $q->whereIn('users.id', $participantUserIds))
            ->withCount(['users' => fn ($q) => $q->whereIn('users.id', $participantUserIds)])
            ->orderByDesc('users_count')
            ->first();

        // If no team matched, try owner's team in that org
        if (! $team) {
            $team = $user->teams()->where('organization_id', $organizationId)->first();
        }

        // Last resort: owner's first team
        if (! $team) {
            $team = $user->teams()->first();
        }

        return $team ? ['team' => $team, 'user' => $user] : null;
    }

    private function resolveOwner(CalendarEvent $event): ?User
    {
        if ($event->creator) {
            return $event->creator;
        }

        return $event->source?->user;
    }

    /**
     * Get user IDs of participants who are linked to system users via profiles.
     */
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
