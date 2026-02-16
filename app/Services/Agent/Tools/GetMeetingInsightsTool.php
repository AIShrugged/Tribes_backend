<?php

namespace App\Services\Agent\Tools;

use App\Models\InsightSource;

class GetMeetingInsightsTool implements ToolInterface
{
    public function getName(): string
    {
        return 'get_meeting_insights';
    }

    public function getDescription(): string
    {
        return '⭐️ PREFERRED METHOD: Get automatically extracted insights from a meeting. Returns key facts about participants extracted by AI during meeting analysis, including communication style, skills, decisions, goals, and personality traits. This is MUCH more efficient than get_transcript and should be your FIRST CHOICE for meeting information. Only use get_transcript if insights are insufficient or user explicitly requests full conversation details.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'calendar_event_id' => [
                    'type' => 'integer',
                    'description' => 'The ID of the meeting to get insights from',
                ],
                'category' => [
                    'type' => 'string',
                    'description' => 'Optional: filter insights by category',
                    'enum' => ['communication_style', 'hard_skills', 'goals_motivations', 'preferences', 'personality_traits'],
                ],
                'email' => [
                    'type' => 'string',
                    'description' => 'Optional: filter insights for a specific participant email',
                ],
            ],
            'required' => ['calendar_event_id'],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $parameters = $parameters ?? [];

        $eventId = $parameters['calendar_event_id'] ?? null;
        $category = $parameters['category'] ?? null;
        $email = $parameters['email'] ?? null;

        if (!$eventId) {
            return [
                'success' => false,
                'error' => 'calendar_event_id is required',
            ];
        }

        // Find insight source for this meeting
        $source = InsightSource::where('source_type', 'transcript')
            ->where('source_id', $eventId)
            ->first();

        if (!$source) {
            return [
                'success' => false,
                'error' => 'No insights found for this meeting. The meeting might not have been processed yet, or no insights were extracted.',
            ];
        }

        // Build query for insight items
        $query = $source->items()->where('is_archived', false);

        if ($category) {
            $query->where('category', $category);
        }

        if ($email) {
            $query->where('email', $email);
        }

        $items = $query->get();

        if ($items->isEmpty()) {
            return [
                'success' => true,
                'meeting_id' => $eventId,
                'insights_count' => 0,
                'message' => 'No insights found matching your filters.',
            ];
        }

        // Group by email, then by category
        $grouped = $items->groupBy('email')->map(function ($userItems, $userEmail) {
            return [
                'email' => $userEmail,
                'insights_by_category' => $userItems->groupBy('category')->map(function ($categoryItems) {
                    return $categoryItems->map(fn ($item) => [
                        'fact' => $item->fact,
                        'confidence' => $item->confidence,
                    ])->toArray();
                })->toArray(),
            ];
        })->values()->toArray();

        return [
            'success' => true,
            'meeting_id' => $eventId,
            'processed_at' => $source->processed_at?->toIso8601String(),
            'insights_count' => $items->count(),
            'participants_with_insights' => $grouped,
        ];
    }
}
