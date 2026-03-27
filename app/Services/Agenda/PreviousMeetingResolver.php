<?php

namespace App\Services\Agenda;

use App\Models\CalendarEvent;

class PreviousMeetingResolver
{
    public function resolve(CalendarEvent $event): ?CalendarEvent
    {
        $organizationIds = $event->sources()
            ->join('users', 'sources.user_id', '=', 'users.id')
            ->join('organization_user', 'users.id', '=', 'organization_user.user_id')
            ->pluck('organization_user.organization_id')
            ->unique();

        if ($organizationIds->isEmpty()) {
            return null;
        }

        return CalendarEvent::query()
            ->whereHas('sources.user.organizations', fn ($q) => $q->whereIn('organizations.id', $organizationIds))
            ->where('title', $event->title)
            ->where('starts_at', '<', $event->starts_at)
            ->whereHas('meetingSummary')
            ->orderByDesc('starts_at')
            ->with('meetingSummary')
            ->first();
    }
}
