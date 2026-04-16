<?php

namespace App\Services;

use App\Domain\DTO\Issue\IssueStatsDTO;
use App\Models\Issue;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class IssueStatsService
{
    public function getStats(User $user): IssueStatsDTO
    {
        $base = Issue::query()->visibleTo($user);

        $byStatus = (clone $base)
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        $total   = (clone $base)->count();
        $overdue = (clone $base)
            ->where('status', '!=', 'done')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now())
            ->count();

        $todayStart     = now()->startOfDay();
        $yesterdayStart = now()->subDay()->startOfDay();
        $yesterdayEnd   = now()->subDay()->endOfDay();

        $deltaInProgress = $this->countDelta($base, 'in_progress', $todayStart, $yesterdayStart, $yesterdayEnd);
        $deltaCompleted  = $this->countDelta($base, 'done', $todayStart, $yesterdayStart, $yesterdayEnd);
        $deltaOverdue    = $this->countOverdueDelta($base, $todayStart, $yesterdayStart, $yesterdayEnd);

        return new IssueStatsDTO(
            total:           $total,
            open:            (int) ($byStatus['open'] ?? 0),
            inProgress:      (int) ($byStatus['in_progress'] ?? 0),
            paused:          (int) ($byStatus['paused'] ?? 0),
            completed:       (int) ($byStatus['done'] ?? 0),
            overdue:         $overdue,
            deltaInProgress: $deltaInProgress,
            deltaCompleted:  $deltaCompleted,
            deltaOverdue:    $deltaOverdue,
        );
    }

    private function countDelta(
        Builder $base,
        string $status,
        Carbon $todayStart,
        Carbon $yesterdayStart,
        Carbon $yesterdayEnd,
    ): int {
        $today     = (clone $base)->where('status', $status)->where('updated_at', '>=', $todayStart)->count();
        $yesterday = (clone $base)->where('status', $status)->whereBetween('updated_at', [$yesterdayStart, $yesterdayEnd])->count();

        return $today - $yesterday;
    }

    private function countOverdueDelta(
        Builder $base,
        Carbon $todayStart,
        Carbon $yesterdayStart,
        Carbon $yesterdayEnd,
    ): int {
        $overdueBase = (clone $base)
            ->where('status', '!=', 'done')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now());

        $today     = (clone $overdueBase)->where('updated_at', '>=', $todayStart)->count();
        $yesterday = (clone $overdueBase)->whereBetween('updated_at', [$yesterdayStart, $yesterdayEnd])->count();

        return $today - $yesterday;
    }
}
