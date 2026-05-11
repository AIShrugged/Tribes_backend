<?php

namespace App\Services\Followup;

use App\Models\CalendarEvent;

class TranscriptBuilderService
{
    public function build(CalendarEvent $event): string
    {
        return $event->transcriptEntries()
            ->with('participant')
            ->orderBy('start_absolute')
            ->get()
            ->map(fn($entry) => sprintf(
                '%s %s: %s',
                $entry->start_relative,
                $entry->participant?->name ?? 'Unknown',
                $entry->text,
            ))
            ->implode("\n");
    }
}
