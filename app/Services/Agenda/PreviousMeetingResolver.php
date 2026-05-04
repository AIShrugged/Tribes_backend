<?php

namespace App\Services\Agenda;

use App\Models\CalendarEvent;
use Illuminate\Support\Collection;

class PreviousMeetingResolver
{
    public function resolve(CalendarEvent $event): ?CalendarEvent
    {
        return $this->resolveMany($event, 1)->first();
    }

    /**
     * @return Collection<int, CalendarEvent>
     */
    public function resolveMany(CalendarEvent $event, int $limit = 3): Collection
    {
        $organizationIds = $this->getOrganizationIds($event);

        if ($organizationIds->isEmpty()) {
            return CalendarEvent::query()
                ->inSameSeriesAs($event)
                ->where('starts_at', '<', $event->starts_at)
                ->whereHas('meetingSummary', fn ($q) => $q->where('status', 'done'))
                ->orderByDesc('starts_at')
                ->with('meetingSummary')
                ->limit($limit)
                ->get();
        }

        return CalendarEvent::query()
            ->where(function ($q) use ($organizationIds) {
                $q->whereHas('source.user.organizations', fn ($q) => $q->whereIn('organizations.id', $organizationIds))
                    ->orWhereHas('sources.user.organizations', fn ($q) => $q->whereIn('organizations.id', $organizationIds));
            })
            ->inSameSeriesAs($event)
            ->where('starts_at', '<', $event->starts_at)
            ->whereHas('meetingSummary', fn ($q) => $q->where('status', 'done'))
            ->orderByDesc('starts_at')
            ->with('meetingSummary')
            ->limit($limit)
            ->get();
    }

    public function getOrganizationIds(CalendarEvent $event): Collection
    {
        $ids = collect();

        // From singular source (BelongsTo)
        $event->loadMissing('source.user.organizations');
        if ($event->source?->user) {
            $ids = $ids->merge($event->source->user->organizations->pluck('id'));
        }

        // From plural sources (BelongsToMany) if any
        $pivotIds = $event->sources()
            ->join('users', 'sources.user_id', '=', 'users.id')
            ->join('organization_user', 'users.id', '=', 'organization_user.user_id')
            ->pluck('organization_user.organization_id');

        $ids = $ids->merge($pivotIds);

        return $ids->unique();
    }
}
