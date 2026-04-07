<?php

namespace App\Services\Insight;

use App\Models\CalendarEvent;
use App\Models\InsightItem;
use App\Models\InsightProfile;
use App\Models\InsightProfileHistory;
use App\Models\InsightRelationship;
use App\Models\InsightShortTerm;
use App\Models\InsightSource;

class InsightService
{
    public function __construct(
        private readonly InsightExtractionService $extraction,
        private readonly InsightEvolutionService $evolution,
        private readonly InsightRetrievalService $retrieval,
    ) {}

    /**
     * Full pipeline: extract from transcript → persist items → trigger evolution.
     */
    public function processTranscript(CalendarEvent $event): array
    {
        return $this->extraction->extract($event);
    }

    /**
     * Get context string for injecting into Tribes Bot prompts.
     */
    public function getContextForQuery(int $profileId, string $query): string
    {
        return $this->retrieval->getContextForQuery($profileId, $query);
    }

    /**
     * Get the full structured profile for a profile_id.
     */
    public function getFullProfile(int $profileId): array
    {
        return $this->retrieval->getFullProfile($profileId);
    }

    /**
     * Get active short-term context for a profile_id.
     */
    public function getShortTermContext(int $profileId): array
    {
        return $this->retrieval->getShortTermContext($profileId);
    }

    /**
     * Get relationship dynamics between two profiles.
     */
    public function getRelationship(int $profileIdA, int $profileIdB): ?array
    {
        return $this->retrieval->getRelationship($profileIdA, $profileIdB);
    }

    /**
     * Trigger a full profile rebuild from all items for a profile_id.
     */
    public function rebuildProfile(int $profileId): void
    {
        $this->evolution->rebuildFromAllItems($profileId);
    }

    /**
     * Delete all insight data for a profile_id (GDPR / forget request).
     */
    public function forget(int $profileId): void
    {
        InsightItem::where('profile_id', $profileId)->delete();
        InsightShortTerm::where('profile_id', $profileId)->delete();

        $insightProfileIds = InsightProfile::where('profile_id', $profileId)->pluck('id');
        InsightProfileHistory::whereIn('insight_profile_id', $insightProfileIds)->delete();
        InsightProfile::where('profile_id', $profileId)->delete();

        InsightRelationship::where('profile_id_a', $profileId)
            ->orWhere('profile_id_b', $profileId)
            ->delete();

        InsightSource::where('profile_id', $profileId)->delete();
    }
}
