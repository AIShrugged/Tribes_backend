<?php

namespace App\Services\Dashboard;

use App\Domain\DTO\Dashboard\FollowupStatsDTO;
use App\Models\Followup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class FollowupStatsService
{
    public function getStats(int $userId): FollowupStatsDTO
    {
        $query = Followup::owned($userId);

        $byStatus = $this->countByStatus($query);

        return new FollowupStatsDTO(
            total:      (clone $query)->count(),
            done:       (int) ($byStatus['done'] ?? 0),
            inProgress: (int) ($byStatus['in_progress'] ?? 0),
            failed:     (int) ($byStatus['failed'] ?? 0),
        );
    }

    private function countByStatus(Builder $query): array
    {
        return (clone $query)
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();
    }
}
