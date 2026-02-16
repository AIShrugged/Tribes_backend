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
     * Weekly: archive expired short-term memories and low-confidence items.
     */
    public function runWeeklyConsolidation(): void
    {
        Log::info('InsightMaintenanceService: starting weekly consolidation');

        $this->archiveExpiredShortTerm();
        $this->archiveLowConfidenceItems();

        Log::info('InsightMaintenanceService: weekly consolidation complete');
    }

    /**
     * Monthly: full profile rebuild from all items for all profiles.
     */
    public function runMonthlyRebuild(): void
    {
        Log::info('InsightMaintenanceService: starting monthly rebuild');

        $profileIds = InsightProfile::distinct()->pluck('profile_id')->toArray();

        foreach ($profileIds as $profileId) {
            try {
                $this->evolution->rebuildFromAllItems($profileId);
                Log::info('InsightMaintenanceService: rebuilt profile', ['profile_id' => $profileId]);
            } catch (\Throwable $e) {
                Log::error('InsightMaintenanceService: rebuild failed', [
                    'profile_id' => $profileId,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        $this->pruneOldHistory();

        Log::info('InsightMaintenanceService: monthly rebuild complete');
    }

    private function archiveExpiredShortTerm(): void
    {
        $deleted = InsightShortTerm::where('expires_at', '<', now())->delete();
        Log::info('InsightMaintenanceService: archived short-term records', ['count' => $deleted]);
    }

    private function archiveLowConfidenceItems(): void
    {
        $archived = InsightItem::where('confidence', '<', 0.3)
            ->where('is_archived', false)
            ->update(['is_archived' => true]);

        Log::info('InsightMaintenanceService: archived low-confidence items', ['count' => $archived]);
    }

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
