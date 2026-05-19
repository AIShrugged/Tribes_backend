<?php

namespace App\Services\Agent\Tools;

use App\Enums\AgendaStatus;
use App\Models\MeetingAgenda;

class GetMeetingAgendaTool extends AbstractAgentTool
{
    public function getName(): string
    {
        return 'get_meeting_agenda';
    }

    public function getDescription(): string
    {
        return 'Get the AI-generated agenda for an upcoming meeting: meeting_goal, discussion_topics, '
             . 'main_problem, commitments_check (from previous meetings), previous_summary excerpts. '
             . 'Returns the raw structured agenda content. '
             . 'Use this when asked "что на встрече", "о чём поговорим", "какая повестка", or to prepare for an upcoming meeting.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'calendar_event_id' => [
                    'type' => 'integer',
                    'description' => 'The calendar event ID to fetch the agenda for.',
                ],
            ],
            'required' => ['calendar_event_id'],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $eventId = $parameters['calendar_event_id'] ?? null;

        if (! $eventId) {
            return [
                'success' => false,
                'error' => 'calendar_event_id is required',
            ];
        }

        $agenda = MeetingAgenda::query()
            ->where('calendar_event_id', $eventId)
            ->whereNull('user_id')
            ->where('type', 'general')
            ->where('status', AgendaStatus::DONE->value)
            ->latest('id')
            ->first();

        if (! $agenda) {
            return [
                'success' => false,
                'error' => 'No completed agenda found for this meeting yet.',
            ];
        }

        $raw = $agenda->raw_json ?? [];

        return [
            'success' => true,
            'calendar_event_id' => (int) $eventId,
            'meeting_goal' => $raw['meeting_goal'] ?? null,
            'main_problem' => $raw['main_problem'] ?? null,
            'discussion_topics' => $raw['discussion_topics'] ?? [],
            'commitments_check' => $raw['commitments_check'] ?? [],
            'previous_summary' => $raw['previous_summary'] ?? null,
            'unresolved_decisions' => $raw['unresolved_decisions'] ?? [],
        ];
    }
}
