<?php

namespace App\Services;

use App\Domain\DTO\Issue\IssueStatsDTO;
use App\Domain\DTO\Issue\IssueStatsHistoryDTO;
use App\Models\Issue;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class IssueStatsService
{
    public function getStats(User $user): IssueStatsDTO
    {
        $now  = now();
        $base = Issue::query()->visibleTo($user);

        $byStatus = (clone $base)
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        $total = (clone $base)->count();

        $todayStart     = $now->copy()->startOfDay();
        $tomorrowStart  = $now->copy()->addDay()->startOfDay();
        $yesterdayStart = $now->copy()->subDay()->startOfDay();
        $yesterdayEnd   = $now->copy()->subDay()->endOfDay();

        // Use range condition (not whereDate) so the index is used
        $overdue = (clone $base)
            ->where('status', '!=', 'done')
            ->whereNotNull('due_date')
            ->where('due_date', '<', $todayStart)
            ->count();

        $deltaInProgress = $this->countDelta($base, 'in_progress', $todayStart, $yesterdayStart, $yesterdayEnd);
        $deltaCompleted  = $this->countDelta($base, 'done', $todayStart, $yesterdayStart, $yesterdayEnd);
        $deltaOverdue    = $this->countOverdueDelta($base, $todayStart, $yesterdayStart, $yesterdayEnd);

        // Single conditional aggregation for all closed-task summary fields
        $weekStart      = $now->copy()->startOfWeek(Carbon::MONDAY);
        $lastWeekStart  = $now->copy()->subWeek()->startOfWeek(Carbon::MONDAY);
        $monthStart     = $now->copy()->startOfMonth();
        $lastMonthStart = $now->copy()->subMonth()->startOfMonth();

        $closedBase    = (clone $base)->where('status', 'done')->whereNotNull('close_date');
        $closedSummary = (clone $closedBase)
            ->selectRaw('
                COUNT(*) FILTER (WHERE close_date >= ? AND close_date < ?) AS closed_today,
                COUNT(*) FILTER (WHERE close_date >= ? AND close_date < ?) AS closed_yesterday,
                COUNT(*) FILTER (WHERE close_date >= ?)                    AS closed_this_week,
                COUNT(*) FILTER (WHERE close_date >= ? AND close_date < ?) AS closed_last_week,
                COUNT(*) FILTER (WHERE close_date >= ?)                    AS closed_this_month,
                COUNT(*) FILTER (WHERE close_date >= ? AND close_date < ?) AS closed_last_month,
                COUNT(*)                                                    AS closed_all_time
            ', [
                $todayStart,     $tomorrowStart,
                $yesterdayStart, $todayStart,
                $weekStart,
                $lastWeekStart,  $weekStart,
                $monthStart,
                $lastMonthStart, $monthStart,
            ])
            ->first();

        $closedToday     = (int) ($closedSummary?->closed_today ?? 0);
        $closedYesterday = (int) ($closedSummary?->closed_yesterday ?? 0);
        $closedThisWeek  = (int) ($closedSummary?->closed_this_week ?? 0);
        $closedLastWeek  = (int) ($closedSummary?->closed_last_week ?? 0);
        $closedThisMonth = (int) ($closedSummary?->closed_this_month ?? 0);
        $closedLastMonth = (int) ($closedSummary?->closed_last_month ?? 0);
        $closedAllTime   = (int) ($closedSummary?->closed_all_time ?? 0);

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
            closedToday:     $closedToday,
            closedThisWeek:  $closedThisWeek,
            closedThisMonth: $closedThisMonth,
            closedAllTime:   $closedAllTime,
            deltaToday:      $closedToday - $closedYesterday,
            deltaWeek:       $closedThisWeek - $closedLastWeek,
            deltaMonth:      $closedThisMonth - $closedLastMonth,
        );
    }

    public function getHistory(User $user, string $period, int $range): IssueStatsHistoryDTO
    {
        $now  = now();
        $base = Issue::query()->visibleTo($user)->where('status', 'done')->whereNotNull('close_date');

        $startDate = match ($period) {
            'day'   => $now->copy()->subDays($range)->startOfDay(),
            'week'  => $now->copy()->subWeeks($range)->startOfWeek(),
            'month' => $now->copy()->subMonths($range)->startOfMonth(),
        };

        // PostgreSQL: DATE_TRUNC truncates to period boundary; cast to date for string key matching.
        // ISO week (Monday start) via DATE_TRUNC('week', ...) which follows ISO 8601 in Postgres.
        $groupExpr = match ($period) {
            'day'   => "DATE_TRUNC('day', close_date)::date",
            'week'  => "DATE_TRUNC('week', close_date)::date",
            'month' => "DATE_TRUNC('month', close_date)::date",
        };

        $rows = (clone $base)
            ->where('close_date', '>=', $startDate)
            ->select(DB::raw("{$groupExpr} as period_date"), DB::raw('COUNT(*) as closed'))
            ->groupBy('period_date')
            ->orderBy('period_date')
            ->get()
            ->keyBy('period_date');

        $items = [];
        for ($i = $range - 1; $i >= 0; $i--) {
            $date    = $this->periodDate($period, $i, $now);
            $items[] = ['date' => $date, 'closed' => (int) ($rows[$date]?->closed ?? 0)];
        }

        return new IssueStatsHistoryDTO(period: $period, range: $range, items: $items);
    }

    private function periodDate(string $period, int $stepsBack, Carbon $now): string
    {
        return match ($period) {
            'day'   => $now->copy()->subDays($stepsBack)->toDateString(),
            // Carbon startOfWeek() defaults to Monday (ISO 8601), matching DATE_TRUNC('week') in Postgres
            'week'  => $now->copy()->subWeeks($stepsBack)->startOfWeek(Carbon::MONDAY)->toDateString(),
            'month' => $now->copy()->subMonths($stepsBack)->startOfMonth()->toDateString(),
        };
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
            ->where('due_date', '<', $todayStart);

        $today     = (clone $overdueBase)->where('updated_at', '>=', $todayStart)->count();
        $yesterday = (clone $overdueBase)->whereBetween('updated_at', [$yesterdayStart, $yesterdayEnd])->count();

        return $today - $yesterday;
    }
}
