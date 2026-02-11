<?php

namespace App\Services\Agent\Tools;

use App\Models\CalendarEvent;

class GetTranscriptTool implements ToolInterface
{
    public function getName(): string
    {
        return 'get_transcript';
    }

    public function getDescription(): string
    {
        return 'Get the transcript of a meeting/calendar event by its ID. Returns formatted transcript with speaker identification and timestamps.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'calendar_event_id' => [
                    'type' => 'integer',
                    'description' => 'The ID of the calendar event to fetch transcript for',
                ],
            ],
            'required' => ['calendar_event_id'],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        // Handle null parameters
        $parameters = $parameters ?? [];

        $calendarEventId = $parameters['calendar_event_id'] ?? null;

        if (!$calendarEventId) {
            return [
                'success' => false,
                'error' => 'calendar_event_id is required',
            ];
        }

        $event = CalendarEvent::with(['participants.profile', 'transcriptEntries'])->find($calendarEventId);

        if (!$event) {
            return [
                'success' => false,
                'error' => 'Calendar event not found',
            ];
        }

        if ($event->transcriptEntries->isEmpty()) {
            return [
                'success' => false,
                'error' => 'Transcript not available for this event',
            ];
        }

        return [
            'success' => true,
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'starts_at' => $event->starts_at,
                'ends_at' => $event->ends_at,
            ],
            'transcript' => $event->transcriptEntries->map(function ($entry) {
                return [
                    'speaker' => $entry->speaker ?? 'Unknown',
                    'text' => $entry->text,
                    'timestamp' => $entry->timestamp ?? null,
                ];
            })->toArray(),
        ];
    }
}