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
     * Called from ExtractInsightItemsListener.
     */
    public function processTranscript(CalendarEvent $event): array
    {
        return $this->extraction->extract($event);
    }

    /**
     * Get context string for injecting into Wanda Bot prompts.
     */
    public function getContextForQuery(string $email, string $query): string
    {
        return $this->retrieval->getContextForQuery($email, $query);
    }

    /**
     * Get the full structured profile for an email.
     */
    public function getFullProfile(string $email): array
    {
        return $this->retrieval->getFullProfile($email);
    }

    /**
     * Get active short-term context (current state) for an email.
     */
    public function getShortTermContext(string $email): array
    {
        return $this->retrieval->getShortTermContext($email);
    }

    /**
     * Get relationship dynamics between two people.
     */
    public function getRelationship(string $emailA, string $emailB): ?array
    {
        return $this->retrieval->getRelationship($emailA, $emailB);
    }

    /**
     * Trigger a full profile rebuild from all items for an email.
     * Used by maintenance jobs.
     */
    public function rebuildProfile(string $email): void
    {
        $this->evolution->rebuildFromAllItems($email);
    }

    /**
     * Delete all insight data for an email (GDPR / forget request).
     */
    public function forget(string $email): void
    {
        InsightItem::where('email', $email)->delete();
        InsightShortTerm::where('email', $email)->delete();

        $profileIds = InsightProfile::where('email', $email)->pluck('id');
        InsightProfileHistory::whereIn('insight_profile_id', $profileIds)->delete();
        InsightProfile::where('email', $email)->delete();

        InsightRelationship::where('email_a', $email)
            ->orWhere('email_b', $email)
            ->delete();

        InsightSource::where('email', $email)->delete();
    }
}
