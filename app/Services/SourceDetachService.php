<?php

namespace App\Services;

use App\Models\Bot;
use App\Models\CalendarEvent;
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
            $source->disconnect();
            $source->delete(); // soft delete
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
        $botIds = CalendarEvent::whereIn('id', $eventIds)->whereNotNull('bot_id')->pluck('bot_id');
        if ($botIds->isNotEmpty()) {
            Bot::whereIn('id', $botIds)->update(['is_active' => false]);
        }

        TranscriptEntry::whereIn('calendar_event_id', $eventIds)->delete();
        Followup::whereIn('calendar_event_id', $eventIds)->delete();
        MeetingSummary::whereIn('calendar_event_id', $eventIds)->delete();
        Issue::where('sourceable_type', 'App\Models\CalendarEvent')
            ->whereIn('sourceable_id', $eventIds)
            ->delete();
    }
}
