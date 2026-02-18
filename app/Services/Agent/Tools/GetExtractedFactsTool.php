<?php

namespace App\Services\Agent\Tools;

use App\Models\InsightItem;
use App\Models\InsightSource;

class GetExtractedFactsTool implements ToolInterface
{
    public function getName(): string
    {
        return 'get_extracted_facts';
    }

    public function getDescription(): string
    {
        return 'Get raw AI-extracted facts about a person from specific sources (meeting transcripts or Telegram chats). Returns individual fact items with confidence scores, grouped by source and category. Use this when you need source-specific facts (e.g. "what did we learn about Ivan at the Friday meeting?"). For an aggregated long-term profile use get_user_insights instead.';
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
                'source_type' => [
                    'type' => 'string',
                    'description' => 'Optional: filter by source type. "transcript" = meeting recordings, "telegram" = Telegram chats. Omit to get facts from all sources.',
                    'enum' => ['transcript', 'telegram'],
                ],
                'source_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: only use with source_type=transcript. Pass calendar_event_id to get facts extracted from a specific meeting. Do NOT use for source_type=telegram — for Telegram facts omit this parameter and filter by source_type=telegram only.',
                ],
                'category' => [
                    'type' => 'string',
                    'description' => 'Optional: filter by fact category.',
                    'enum' => ['communication_style', 'work_patterns', 'strengths', 'development_areas', 'goals_motivations', 'psychological_profile'],
                ],
            ],
            'required' => ['profile_id'],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $parameters = $parameters ?? [];

        $profileId  = $parameters['profile_id'] ?? null;
        $sourceType = $parameters['source_type'] ?? null;
        $sourceId   = $parameters['source_id'] ?? null;
        $category   = $parameters['category'] ?? null;

        if (!$profileId) {
            return [
                'success' => false,
                'error'   => 'profile_id is required',
            ];
        }

        // Find insight sources for this person
        $sourcesQuery = InsightSource::where('profile_id', $profileId);

        if ($sourceType) {
            $sourcesQuery->where('source_type', $sourceType);
        }

        if ($sourceId) {
            $sourcesQuery->where('source_id', $sourceId);
        }

        $sources = $sourcesQuery->get();

        if ($sources->isEmpty()) {
            return [
                'success' => false,
                'error'   => 'No extracted facts found for this person.'
                    . ($sourceId ? ' The source might not have been processed yet.' : ''),
            ];
        }

        $sourceIds = $sources->pluck('id');

        // Load insight items
        $itemsQuery = InsightItem::whereIn('insight_source_id', $sourceIds)
            ->where('is_archived', false);

        if ($category) {
            $itemsQuery->where('category', $category);
        }

        $items = $itemsQuery->orderBy('confidence', 'desc')->get();

        if ($items->isEmpty()) {
            return [
                'success'     => true,
                'profile_id'  => $profileId,
                'facts_count' => 0,
                'message'     => 'No facts found matching your filters.',
            ];
        }

        // Group by source, then by category
        $itemsBySource = $items->groupBy('insight_source_id');

        $bySource = $sources->map(function (InsightSource $source) use ($itemsBySource) {
            $sourceItems = $itemsBySource->get($source->id, collect());

            if ($sourceItems->isEmpty()) {
                return null;
            }

            return [
                'source_type'      => $source->source_type,
                'source_id'        => $source->source_id,
                'processed_at'     => $source->processed_at?->toIso8601String(),
                'facts_by_category' => $sourceItems->groupBy('category')->map(function ($categoryItems) {
                    return $categoryItems->map(fn ($item) => [
                        'fact'       => $item->fact,
                        'confidence' => $item->confidence,
                    ])->toArray();
                })->toArray(),
            ];
        })->filter()->values()->toArray();

        return [
            'success'     => true,
            'profile_id'  => $profileId,
            'facts_count' => $items->count(),
            'sources'     => $bySource,
        ];
    }
}
