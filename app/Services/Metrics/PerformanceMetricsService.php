<?php

namespace App\Services\Metrics;

use App\Models\Issue;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Computes on-the-fly performance metrics for users, teams, and organizations.
 *
 * No materialized storage — uses composite indexes on issues table.
 * Lead time = avg(close_date - created_at) where status='done'; precise (not approximated)
 * because IssueObserver maintains close_date on status transitions.
 *
 * Period format: ['from' => Carbon, 'to' => Carbon].
 * Returns associative arrays (no DTOs).
 */
class PerformanceMetricsService
{
    /**
     * Open statuses (in_progress count).
     */
    private const OPEN_STATUSES = ['open', 'in_progress', 'paused', 'review', 'reopen'];

    /**
     * @return array{done: int, in_progress: int, overdue: int, velocity_week: int, avg_lead_time_days: ?float}
     */
    public function forUser(User $user, array $period): array
    {
        $batch = $this->forUsers(new Collection([$user]), $period);

        return $batch[$user->id] ?? $this->emptyMetrics();
    }

    /**
     * Batch lookup — returns map [user_id => metrics array].
     * Used by TeamDashboardService::peopleTab to avoid N+1.
     *
     * @return array<int, array{done: int, in_progress: int, overdue: int, velocity_week: int, avg_lead_time_days: ?float, ai_calls_week: int, chat_messages_week: int}>
     */
    public function forUsers(Collection $users, array $period): array
    {
        $userIds = $users->pluck('id')->all();

        if (empty($userIds)) {
            return [];
        }

        $base = fn () => Issue::query()->withoutTrashed()->whereIn('assignee_id', $userIds);

        // Done count + avg lead time, grouped by assignee
        $doneAgg = $base()
            ->where('status', 'done')
            ->whereBetween('close_date', [$period['from'], $period['to']])
            ->select('assignee_id', DB::raw('COUNT(*) as done_count'), DB::raw('AVG(EXTRACT(EPOCH FROM (close_date - created_at))) as avg_seconds'))
            ->groupBy('assignee_id')
            ->get()
            ->keyBy('assignee_id');

        // In-progress (open statuses; period-independent)
        $inProgress = $base()
            ->whereIn('status', self::OPEN_STATUSES)
            ->select('assignee_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('assignee_id')
            ->pluck('cnt', 'assignee_id');

        // Overdue (open statuses with due_date < now)
        $overdue = $base()
            ->whereIn('status', self::OPEN_STATUSES)
            ->whereNotNull('due_date')
            ->where('due_date', '<', now()->toDateString())
            ->select('assignee_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('assignee_id')
            ->pluck('cnt', 'assignee_id');

        // Velocity (done in last 7 days, period-independent)
        $velocity = $base()
            ->where('status', 'done')
            ->whereBetween('close_date', [now()->subWeek(), now()])
            ->select('assignee_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('assignee_id')
            ->pluck('cnt', 'assignee_id');

        // AI calls (last 7 days, period-independent — same rolling window as velocity)
        $aiCalls = $this->aiUsageForUsers($userIds);

        // Chat activity (last 7 days, only role='user' in chats bound to an organization)
        $chatMessages = $this->chatActivityForUsers($userIds);

        // Cycle time per assignee from issue_status_histories
        $cycle = $this->cycleTimeForUsers($userIds, $period);

        $result = [];
        foreach ($userIds as $userId) {
            $doneRow = $doneAgg->get($userId);
            $avgSeconds = $doneRow?->avg_seconds;

            $result[$userId] = [
                'done' => (int) ($doneRow->done_count ?? 0),
                'in_progress' => (int) ($inProgress[$userId] ?? 0),
                'overdue' => (int) ($overdue[$userId] ?? 0),
                'velocity_week' => (int) ($velocity[$userId] ?? 0),
                'avg_lead_time_days' => $avgSeconds !== null
                    ? round((float) $avgSeconds / 86400, 2)
                    : null,
                'avg_cycle_time_days' => $cycle[$userId] ?? null,
                'ai_calls_week' => (int) ($aiCalls[$userId] ?? 0),
                'chat_messages_week' => (int) ($chatMessages[$userId] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * Average cycle time per assignee, in days. Cycle time = time from first transition
     * into 'in_progress' until the issue closes (status='done', close_date in period).
     *
     * Only issues whose `assignee_id` is in $userIds AND have at least one history row
     * with `to_status='in_progress'` AND were closed in [$period['from'], $period['to']]
     * are counted. Issues created before the migration that have no history yield null.
     *
     * @param  int[]  $userIds
     * @return array<int, float|null>  user_id => avg cycle in days
     */
    private function cycleTimeForUsers(array $userIds, array $period): array
    {
        if (empty($userIds)) {
            return [];
        }

        // Per-issue: collapse history to MIN(in_progress changed_at), then compute close_date − that.
        // GROUP BY i.id makes it one row per issue regardless of how many history rows match.
        $rows = DB::table('issues as i')
            ->join('issue_status_histories as h', 'h.issue_id', '=', 'i.id')
            ->whereIn('i.assignee_id', $userIds)
            ->whereNull('i.deleted_at')
            ->where('i.status', 'done')
            ->whereBetween('i.close_date', [$period['from'], $period['to']])
            ->where('h.to_status', 'in_progress')
            ->select(
                'i.id',
                'i.assignee_id',
                DB::raw('EXTRACT(EPOCH FROM (i.close_date - MIN(h.changed_at))) as cycle_seconds')
            )
            ->groupBy('i.id', 'i.assignee_id', 'i.close_date')
            ->get();

        // Aggregate per-user in PHP (avoids nested SELECT).
        $byUser = [];
        foreach ($rows as $row) {
            $byUser[$row->assignee_id][] = (float) $row->cycle_seconds;
        }

        $result = [];
        foreach ($byUser as $userId => $values) {
            $values = array_filter($values, fn ($v) => $v >= 0);
            $result[$userId] = empty($values)
                ? null
                : round(array_sum($values) / count($values) / 86400, 2);
        }
        return $result;
    }

    /**
     * Count agent_activity_logs rows per user within the last 7 days.
     * No filtering by tool_name — every logged event counts, including system
     * pipeline events (insight_evolved, upcoming_agenda_generated, etc.).
     *
     * @param  int[]  $userIds
     * @return array<int, int>  user_id => count
     */
    private function aiUsageForUsers(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        return DB::table('agent_activity_logs')
            ->whereIn('user_id', $userIds)
            ->whereBetween('created_at', [now()->subWeek(), now()])
            ->select('user_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('user_id')
            ->pluck('cnt', 'user_id')
            ->all();
    }

    /**
     * Count user-role messages per user in chats bound to an organization, within the
     * last 7 days. The join chain is:
     *   telegram_chat_messages
     *     → telegram_chat_registrations (chat must be bound: organization_id IS NOT NULL)
     *     → telegram_users (resolve sender_tg_id to internal user_id)
     *
     * Messages in unbound or DM chats are ignored — only project chats count.
     *
     * @param  int[]  $userIds
     * @return array<int, int>  user_id => count
     */
    private function chatActivityForUsers(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        return DB::table('telegram_chat_messages as m')
            ->join('telegram_chat_registrations as r', 'm.telegram_chat_id', '=', 'r.telegram_chat_id')
            ->join('telegram_users as tu', 'm.telegram_user_id', '=', 'tu.telegram_user_id')
            ->whereNotNull('r.organization_id')
            ->whereIn('tu.user_id', $userIds)
            ->where('m.role', 'user')
            ->whereBetween('m.created_at', [now()->subWeek(), now()])
            ->select('tu.user_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('tu.user_id')
            ->pluck('cnt', 'user_id')
            ->all();
    }

    /**
     * Team metrics: aggregate + per-member breakdown.
     *
     * @return array{aggregated: array, by_member: array<int, array>}
     */
    public function forTeam(Team $team, array $period): array
    {
        $team->loadMissing('users');

        $byMember = $this->forUsers($team->users, $period);

        // Issue-scoped metrics come from team_id filter (catches issues with no/outside-team assignee).
        // AI calls + chat messages are user-bound — sum across team members.
        $teamScopeMetrics = $this->aggregateForScope(
            Issue::query()->withoutTrashed()->where('team_id', $team->id),
            $period,
        );

        $teamScopeMetrics['ai_calls_week'] = array_sum(array_column($byMember, 'ai_calls_week'));
        $teamScopeMetrics['chat_messages_week'] = array_sum(array_column($byMember, 'chat_messages_week'));

        return [
            'aggregated' => $teamScopeMetrics,
            'by_member' => $byMember,
        ];
    }

    /**
     * Org metrics: aggregate + per-team breakdown.
     *
     * @return array{aggregated: array, by_team: array<int, array>}
     */
    public function forOrg(Organization $org, array $period): array
    {
        $org->loadMissing('teams.users', 'users');

        $byTeam = [];
        foreach ($org->teams as $team) {
            $byTeam[$team->id] = $this->forTeam($team, $period);
        }

        $orgScopeMetrics = $this->aggregateForScope(
            Issue::query()->withoutTrashed()->where('organization_id', $org->id),
            $period,
        );

        // Sum AI calls and chat activity across all org members — single batched query per metric.
        $orgUserIds = $org->users->pluck('id')->all();
        $orgScopeMetrics['ai_calls_week'] = array_sum($this->aiUsageForUsers($orgUserIds));
        $orgScopeMetrics['chat_messages_week'] = array_sum($this->chatActivityForUsers($orgUserIds));

        return [
            'aggregated' => $orgScopeMetrics,
            'by_team' => $byTeam,
        ];
    }

    /**
     * Aggregate metrics for a scoped Issue query (team or org).
     */
    private function aggregateForScope(Builder $scope, array $period): array
    {
        $done = (clone $scope)
            ->where('status', 'done')
            ->whereBetween('close_date', [$period['from'], $period['to']])
            ->count();

        $inProgress = (clone $scope)
            ->whereIn('status', self::OPEN_STATUSES)
            ->count();

        $overdue = (clone $scope)
            ->whereIn('status', self::OPEN_STATUSES)
            ->whereNotNull('due_date')
            ->where('due_date', '<', now()->toDateString())
            ->count();

        $velocity = (clone $scope)
            ->where('status', 'done')
            ->whereBetween('close_date', [now()->subWeek(), now()])
            ->count();

        $avgSeconds = (clone $scope)
            ->where('status', 'done')
            ->whereBetween('close_date', [$period['from'], $period['to']])
            ->avg(DB::raw('EXTRACT(EPOCH FROM (close_date - created_at))'));

        // Average cycle time for the scoped issues (team or org).
        $cycleRows = (clone $scope)
            ->join('issue_status_histories as h', 'h.issue_id', '=', 'issues.id')
            ->where('issues.status', 'done')
            ->whereBetween('issues.close_date', [$period['from'], $period['to']])
            ->where('h.to_status', 'in_progress')
            ->select(
                'issues.id',
                DB::raw('EXTRACT(EPOCH FROM (issues.close_date - MIN(h.changed_at))) as cycle_seconds')
            )
            ->groupBy('issues.id', 'issues.close_date')
            ->get();

        $cycleAvg = null;
        if ($cycleRows->isNotEmpty()) {
            $values = $cycleRows->pluck('cycle_seconds')->filter(fn ($v) => $v >= 0)->all();
            if (! empty($values)) {
                $cycleAvg = round(array_sum($values) / count($values) / 86400, 2);
            }
        }

        return [
            'done' => $done,
            'in_progress' => $inProgress,
            'overdue' => $overdue,
            'velocity_week' => $velocity,
            'avg_lead_time_days' => $avgSeconds !== null
                ? round((float) $avgSeconds / 86400, 2)
                : null,
            'avg_cycle_time_days' => $cycleAvg,
        ];
    }

    /**
     * Convenience: period for the current week (Monday → now).
     */
    public static function thisWeek(): array
    {
        return [
            'from' => Carbon::now()->startOfWeek(Carbon::MONDAY),
            'to' => Carbon::now(),
        ];
    }

    /**
     * Convenience: period for the last 7 days.
     */
    public static function last7Days(): array
    {
        return [
            'from' => Carbon::now()->subWeek(),
            'to' => Carbon::now(),
        ];
    }

    /**
     * Convenience: period for today.
     */
    public static function today(): array
    {
        return [
            'from' => Carbon::now()->startOfDay(),
            'to' => Carbon::now()->endOfDay(),
        ];
    }

    private function emptyMetrics(): array
    {
        return [
            'done' => 0,
            'in_progress' => 0,
            'overdue' => 0,
            'velocity_week' => 0,
            'avg_lead_time_days' => null,
            'avg_cycle_time_days' => null,
            'ai_calls_week' => 0,
            'chat_messages_week' => 0,
        ];
    }
}
