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
                $q->whereHas('source', fn ($q) => $q->whereIn('organization_id', $organizationIds))
                    ->orWhereHas('sources', fn ($q) => $q->whereIn('sources.organization_id', $organizationIds));
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

        $event->loadMissing('source');
        if ($event->source?->organization_id) {
            $ids->push($event->source->organization_id);
        }

        $pivotIds = $event->sources()->whereNotNull('sources.organization_id')->pluck('sources.organization_id');

        return $ids->merge($pivotIds)->unique();
    }
}
