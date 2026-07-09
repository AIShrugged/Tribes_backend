<?php

namespace App\Services\Agent\Tools;

use App\Models\MeetingSummary;
use App\Models\TranscriptUpload;
use App\Services\Agent\Tools\Concerns\InteractsWithMcpTenant;

class GetMeetingSummaryTool extends AbstractAgentTool
{
    use InteractsWithMcpTenant;

    public function getName(): string
    {
        return 'get_meeting_summary';
    }

    public function getDescription(): string
    {
        return 'Get the AI-generated summary of a meeting: overall summary text, key points, decisions made, participants, and meeting date/time. Use this when asked what was discussed or decided at a specific meeting. Faster and more structured than get_transcript.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'calendar_event_id' => [
                    'type' => 'integer',
                    'description' => 'The ID of the calendar event (meeting) to get the summary for.',
                ],
            ],
            'required' => ['calendar_event_id'],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $parameters = $parameters ?? [];

        $eventId = $parameters['calendar_event_id'] ?? null;

        if (! $eventId) {
            return [
                'success' => false,
                'error' => 'calendar_event_id is required',
            ];
        }

        if (! $this->assertCanAccessMeeting((int) $eventId)) {
            return [
                'success' => false,
                'error' => 'Meeting not found or not accessible.',
            ];
        }

        $summary = MeetingSummary::with('calendarEvent.participants')
            ->where('calendar_event_id', $eventId)
            ->first();

        if (! $summary) {
            // Distinguish "no transcript was ever captured" (nothing to process — likely the
            // meeting was not recorded) from "transcript exists but not yet summarized"
            // (genuine processing lag). Collapsing the two makes an agent infer a stalled
            // pipeline from meetings that simply have no input.
            $hasTranscript = TranscriptUpload::where('calendar_event_id', $eventId)->exists();

            return [
                'success' => false,
                'reason' => $hasTranscript ? 'not_processed' : 'no_transcript',
                'error' => $hasTranscript
                    ? 'No summary yet: a transcript exists but has not been processed. Try again later.'
                    : 'No summary: no transcript has been captured for this meeting (it was likely not recorded). There is nothing to summarize — this is not a processing backlog.',
            ];
        }

        if ($summary->status === 'in_progress') {
            return [
                'success' => false,
                'error' => 'Meeting summary is still being generated. Please try again later.',
            ];
        }

        if ($summary->status === 'failed') {
            return [
                'success' => false,
                'error' => 'Meeting summary generation failed for this meeting.',
            ];
        }

        $event = $summary->calendarEvent;
        $participants = $event?->participants->map(fn ($p) => array_filter([
            'name' => $p->name,
            'profile_id' => $p->profile_id ?? null,
        ], fn ($v) => $v !== null))->toArray() ?? [];

        return [
            'success' => true,
            'calendar_event_id' => $eventId,
            'title' => $summary->title,
            'starts_at' => $event?->starts_at ? \Carbon\Carbon::parse($event->starts_at)->toIso8601String() : null,
            'ends_at' => $event?->ends_at ? \Carbon\Carbon::parse($event->ends_at)->toIso8601String() : null,
            'participants' => $participants,
            'summary' => $summary->summary,
            'key_points' => $summary->key_points ?? [],
            'decisions' => $summary->decisions ?? [],
        ];
    }
}
