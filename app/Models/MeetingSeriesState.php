<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingSeriesState extends Model
{
    protected $guarded = [];

    public function sourceEvent(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class, 'source_event_id');
    }

    public static function buildSeriesIdentifier(CalendarEvent $event): string
    {
        $event->loadMissing('source', 'sources');

        $organizationIds = collect();

        if ($event->source?->organization_id) {
            $organizationIds->push($event->source->organization_id);
        }

        $pivotIds = $event->sources()->whereNotNull('sources.organization_id')->pluck('sources.organization_id');

        $organizationIds = $organizationIds->merge($pivotIds)
            ->unique()
            ->sort()
            ->implode(',');

        $seriesKey = $event->url ?? $event->title;

        return md5($seriesKey . '|' . $organizationIds);
    }
}
