<?php

namespace App\Services\Agent\Tools;

use App\Models\InsightProfile;
use App\Models\InsightProfileHistory;

/**
 * Retrieves the version history of a person's insight profile to track changes over time.
 *
 * Example questions this tool answers:
 * - "Как изменился профиль Ивана за последние месяцы?"
 * - "Изменился ли коммуникационный стиль Марии с начала года?"
 * - "Has Anna's communication style evolved over time?"
 * - "Покажи историю обновлений профиля навыков Алексея."
 * - "When did we first start tracking Ivan's goals and motivations?"
 * - "Show me how Ivan's personality profile has changed across versions."
 */
class GetInsightProfileHistoryTool extends AbstractAgentTool
{
    public function getName(): string
    {
        return 'get_insight_profile_history';
    }

    public function getDescription(): string
    {
        return 'Get the version history of a person\'s insight profile — how their profile has changed over time. Each entry is a timestamped snapshot of the profile content at a specific version. Use this when asked about dynamics, changes, or evolution in a person\'s behaviour, skills, or communication style over time. Requires profile_id (get it from get_user_info).';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'profile_id' => [
                    'type' => 'integer',
                    'description' => 'The profile_id of the person. Use get_user_info to find the profile_id first.',
                ],
                'category' => [
                    'type' => 'string',
                    'description' => 'Optional: filter history by a specific insight category.',
                    'enum' => ['communication_style', 'work_patterns', 'strengths', 'development_areas', 'goals_motivations', 'psychological_profile'],
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Optional: maximum number of history entries to return. Default is 10, maximum is 50.',
                ],
            ],
            'required' => ['profile_id'],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $parameters = $parameters ?? [];

        $profileId = $parameters['profile_id'] ?? null;
        $category  = $parameters['category'] ?? null;
        $limit     = min((int) ($parameters['limit'] ?? 10), 50);

        if (! $profileId) {
            return [
                'success' => false,
                'error'   => 'profile_id is required',
            ];
        }

        // Find insight_profile_ids for this person (optionally filtered by category)
        $insightProfileIds = InsightProfile::where('profile_id', $profileId)
            ->when($category, fn ($q) => $q->where('category', $category))
            ->pluck('id');

        if ($insightProfileIds->isEmpty()) {
            return [
                'success'    => false,
                'profile_id' => $profileId,
                'error'      => 'No insight profile found for this person. They might not have enough data yet.',
            ];
        }

        $history = InsightProfileHistory::whereIn('insight_profile_id', $insightProfileIds)
            ->when($category, fn ($q) => $q->where('category', $category))
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        if ($history->isEmpty()) {
            return [
                'success'       => true,
                'profile_id'    => $profileId,
                'history_count' => 0,
                'message'       => 'No history entries found. The profile may not have been updated yet.',
            ];
        }

        return [
            'success'       => true,
            'profile_id'    => $profileId,
            'history_count' => $history->count(),
            'history'       => $history->map(fn ($entry) => [
                'version'    => $entry->version,
                'category'   => $entry->category,
                'content'    => $entry->content,
                'created_at' => $entry->created_at?->toIso8601String(),
            ])->toArray(),
        ];
    }
}
