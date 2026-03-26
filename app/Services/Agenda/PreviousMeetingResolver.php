<?php

namespace App\Services\Agenda;

use App\Models\CalendarEvent;

class PreviousMeetingResolver
{
    public function resolve(CalendarEvent $event): ?CalendarEvent
    {
        $organizationIds = $event->source->user->organizations()->pluck('organizations.id');

        if ($organizationIds->isEmpty()) {
            return null;
        }

        return CalendarEvent::query()
            ->whereHas('source.user.organizations', fn ($q) => $q->whereIn('organizations.id', $organizationIds))
            ->where('title', $event->title)
            ->where('starts_at', '<', $event->starts_at)
            ->whereHas('meetingSummary')
            ->orderByDesc('starts_at')
            ->with('meetingSummary')
            ->first();
    }
}
