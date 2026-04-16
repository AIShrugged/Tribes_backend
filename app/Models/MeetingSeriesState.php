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
        $organizationIds = collect();

        // From singular source (BelongsTo)
        $event->loadMissing('source.user.organizations');
        if ($event->source?->user) {
            $organizationIds = $organizationIds->merge(
                $event->source->user->organizations->pluck('id'),
            );
        }

        // From plural sources (BelongsToMany) if any
        $pivotIds = $event->sources()
            ->join('users', 'sources.user_id', '=', 'users.id')
            ->join('organization_user', 'users.id', '=', 'organization_user.user_id')
            ->pluck('organization_user.organization_id');

        $organizationIds = $organizationIds->merge($pivotIds)
            ->unique()
            ->sort()
            ->implode(',');

        $seriesKey = $event->url ?? $event->title;

        return md5($seriesKey . '|' . $organizationIds);
    }
}
