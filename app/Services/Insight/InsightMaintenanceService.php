<?php

namespace App\Services\Insight;

use App\Models\InsightItem;
use App\Models\InsightProfile;
use App\Models\InsightProfileHistory;
use App\Models\InsightShortTerm;
use Illuminate\Support\Facades\Log;

class InsightMaintenanceService
{
    public function __construct(
        private readonly InsightEvolutionService $evolution,
    ) {}

    /**
     * Weekly: archive expired short-term memories and rebuild profiles that have enough new data.
     */
    public function runWeeklyConsolidation(): void
    {
        Log::info('InsightMaintenanceService: starting weekly consolidation');

        $this->archiveExpiredShortTerm();
        $this->archiveLowConfidenceItems();

        Log::info('InsightMaintenanceService: weekly consolidation complete');
    }

    /**
     * Monthly: full profile rebuild from all items for all emails.
     */
    public function runMonthlyRebuild(): void
    {
        Log::info('InsightMaintenanceService: starting monthly rebuild');

        $emails = InsightProfile::distinct()->pluck('email')->toArray();

        foreach ($emails as $email) {
            try {
                $this->evolution->rebuildFromAllItems($email);
                Log::info('InsightMaintenanceService: rebuilt profile', ['email' => $email]);
            } catch (\Throwable $e) {
                Log::error('InsightMaintenanceService: rebuild failed', [
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->pruneOldHistory();

        Log::info('InsightMaintenanceService: monthly rebuild complete');
    }

    /**
     * Delete expired short-term memory records.
     */
    private function archiveExpiredShortTerm(): void
    {
        $deleted = InsightShortTerm::where('expires_at', '<', now())->delete();
        Log::info('InsightMaintenanceService: archived short-term records', ['count' => $deleted]);
    }

    /**
     * Archive items with very low confidence (< 0.3) — likely noise.
     */
    private function archiveLowConfidenceItems(): void
    {
        $archived = InsightItem::where('confidence', '<', 0.3)
            ->where('is_archived', false)
            ->update(['is_archived' => true]);

        Log::info('InsightMaintenanceService: archived low-confidence items', ['count' => $archived]);
    }

    /**
     * Keep only last 10 history versions per profile to save space.
     */
    private function pruneOldHistory(): void
    {
        $profiles = InsightProfile::all();

        foreach ($profiles as $profile) {
            $historyIds = InsightProfileHistory::where('insight_profile_id', $profile->id)
                ->orderByDesc('version')
                ->skip(10)
                ->pluck('id');

            if ($historyIds->isNotEmpty()) {
                InsightProfileHistory::whereIn('id', $historyIds)->delete();
            }
        }

        Log::info('InsightMaintenanceService: pruned old history');
    }
}
