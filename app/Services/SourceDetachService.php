<?php

namespace App\Services;

use App\Models\Bot;
use App\Models\Followup;
use App\Models\Issue;
use App\Models\MeetingSummary;
use App\Models\Source;
use App\Models\SourceOauth;
use App\Models\TranscriptEntry;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SourceDetachService
{
    public function detach(Source $source): void
    {
        RecallCalendarService::detach($source->external_id);

        DB::transaction(function () use ($source) {
            $this->deleteUpcomingEvents($source);

            SourceOauth::where('source_id', $source->id)->delete();
            $source->delete();
        });
    }

    private function deleteUpcomingEvents(Source $source): void
    {
        $eventIds = $source->calendarEvents()
            ->where('starts_at', '>', Carbon::now())
            ->pluck('id');

        if ($eventIds->isEmpty()) {
            return;
        }

        $this->deleteEventRelations($eventIds);

        $source->calendarEvents()
            ->whereIn('id', $eventIds)
            ->delete();
    }

    private function deleteEventRelations(Collection $eventIds): void
    {
        Bot::whereIn('calendar_event_id', $eventIds)->delete();
        TranscriptEntry::whereIn('calendar_event_id', $eventIds)->delete();
        Followup::whereIn('calendar_event_id', $eventIds)->delete();
        MeetingSummary::whereIn('calendar_event_id', $eventIds)->delete();
        Issue::where('sourceable_type', 'App\Models\CalendarEvent')
            ->whereIn('sourceable_id', $eventIds)
            ->delete();
    }
}
