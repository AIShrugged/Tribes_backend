<?php

namespace App\Services\Agent\Tools;

use App\Models\Followup;

/**
 * Retrieves AI-generated followup assessments produced after a meeting.
 *
 * Example questions this tool answers:
 * - "Какой фидбек был дан Ивану после встречи?"
 * - "Покажи результаты оценки участников встречи в пятницу."
 * - "What was the followup report for the standup on Monday?"
 * - "Есть ли готовые оценки по итогам встречи?"
 * - "Покажи итоги ревью по методологии DISC."
 * - "Was the followup for user 5 generated successfully?"
 */
class GetFollowupTool implements ToolInterface
{
    public function getName(): string
    {
        return 'get_followup';
    }

    public function getDescription(): string
    {
        return 'Get AI-generated followup assessments created after a meeting. Each followup contains a structured evaluation of a participant produced according to a methodology (e.g. DISC, 360-review). Use this when asked about post-meeting assessments, feedback reports, or evaluation results for a specific meeting or user.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'calendar_event_id' => [
                    'type' => 'integer',
                    'description' => 'The ID of the calendar event (meeting) to get followups for.',
                ],
                'user_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: filter followups for a specific user by their user_id.',
                ],
                'status' => [
                    'type' => 'string',
                    'description' => 'Optional: filter by processing status.',
                    'enum' => ['in_progress', 'done', 'failed'],
                ],
            ],
            'required' => ['calendar_event_id'],
        ];
    }

    private function decodeText(mixed $text): mixed
    {
        if (! is_string($text)) {
            return $text;
        }

        $decoded = json_decode($text, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $text;
    }

    public function execute(?array $parameters): mixed
    {
        $parameters = $parameters ?? [];

        $eventId = $parameters['calendar_event_id'] ?? null;
        $userId  = $parameters['user_id'] ?? null;
        $status  = $parameters['status'] ?? null;

        if (! $eventId) {
            return [
                'success' => false,
                'error'   => 'calendar_event_id is required',
            ];
        }

        $query = Followup::with(['user', 'team', 'methodology'])
            ->where('calendar_event_id', $eventId);

        if ($userId) {
            $query->where('user_id', $userId);
        }

        if ($status) {
            $query->where('status', $status);
        }

        $followups = $query->get();

        if ($followups->isEmpty()) {
            return [
                'success'           => true,
                'calendar_event_id' => $eventId,
                'followups_count'   => 0,
                'message'           => 'No followups found for this meeting. They might still be processing or have not been generated yet.',
            ];
        }

        return [
            'success'           => true,
            'calendar_event_id' => $eventId,
            'followups_count'   => $followups->count(),
            'followups'         => $followups->map(fn ($followup) => [
                'id'             => $followup->id,
                'user_id'        => $followup->user_id,
                'user_name'      => $followup->user?->name,
                'team_id'        => $followup->team_id,
                'team_name'      => $followup->team?->name,
                'methodology_id' => $followup->methodology_id,
                'methodology'    => $followup->methodology?->name,
                'status'         => $followup->status,
                'text'           => $this->decodeText($followup->text),
            ])->toArray(),
        ];
    }
}
