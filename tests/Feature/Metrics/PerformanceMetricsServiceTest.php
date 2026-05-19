<?php

namespace Tests\Feature\Metrics;

use App\Models\Issue;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\Metrics\PerformanceMetricsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PerformanceMetricsServiceTest extends TestCase
{
    use RefreshDatabase;

    private PerformanceMetricsService $service;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PerformanceMetricsService::class);
        $this->user = User::factory()->create();
    }

    #[Test]
    public function it_counts_done_in_period_using_close_date(): void
    {
        $period = PerformanceMetricsService::last7Days();

        // 2 done in period
        Issue::create(['name' => 'A', 'assignee_id' => $this->user->id, 'status' => 'done', 'close_date' => now()->subDay()]);
        Issue::create(['name' => 'B', 'assignee_id' => $this->user->id, 'status' => 'done', 'close_date' => now()->subDay()]);
        // 1 done outside period (last month)
        Issue::create(['name' => 'C', 'assignee_id' => $this->user->id, 'status' => 'done', 'close_date' => now()->subMonth()]);

        $metrics = $this->service->forUser($this->user, $period);

        $this->assertSame(2, $metrics['done']);
    }

    #[Test]
    public function it_counts_in_progress_across_open_statuses(): void
    {
        Issue::create(['name' => 'A', 'assignee_id' => $this->user->id, 'status' => 'open']);
        Issue::create(['name' => 'B', 'assignee_id' => $this->user->id, 'status' => 'in_progress']);
        Issue::create(['name' => 'C', 'assignee_id' => $this->user->id, 'status' => 'review']);
        Issue::create(['name' => 'D', 'assignee_id' => $this->user->id, 'status' => 'done', 'close_date' => now()]);

        $metrics = $this->service->forUser($this->user, PerformanceMetricsService::last7Days());

        $this->assertSame(3, $metrics['in_progress']);
    }

    #[Test]
    public function it_counts_overdue_only_for_open_statuses(): void
    {
        Issue::create(['name' => 'A', 'assignee_id' => $this->user->id, 'status' => 'open', 'due_date' => now()->subDay()]);
        Issue::create(['name' => 'B', 'assignee_id' => $this->user->id, 'status' => 'in_progress', 'due_date' => now()->subDay()]);
        // Done — not overdue even with past due_date
        Issue::create(['name' => 'C', 'assignee_id' => $this->user->id, 'status' => 'done', 'close_date' => now(), 'due_date' => now()->subDay()]);
        // Future due — not overdue
        Issue::create(['name' => 'D', 'assignee_id' => $this->user->id, 'status' => 'open', 'due_date' => now()->addDay()]);

        $metrics = $this->service->forUser($this->user, PerformanceMetricsService::last7Days());

        $this->assertSame(2, $metrics['overdue']);
    }

    #[Test]
    public function it_computes_avg_lead_time_from_close_date(): void
    {
        Issue::create([
            'name' => 'Fast',
            'assignee_id' => $this->user->id,
            'status' => 'done',
            'created_at' => now()->subDays(2),
            'close_date' => now()->subDay(),
        ]);
        Issue::create([
            'name' => 'Slow',
            'assignee_id' => $this->user->id,
            'status' => 'done',
            'created_at' => now()->subDays(7),
            'close_date' => now()->subDay(),
        ]);

        $metrics = $this->service->forUser($this->user, PerformanceMetricsService::last7Days());

        // (1 day + 6 days) / 2 = 3.5 days
        $this->assertNotNull($metrics['avg_lead_time_days']);
        $this->assertGreaterThan(3.0, $metrics['avg_lead_time_days']);
        $this->assertLessThan(4.0, $metrics['avg_lead_time_days']);
    }

    #[Test]
    public function it_returns_zeros_for_user_with_no_issues(): void
    {
        $metrics = $this->service->forUser($this->user, PerformanceMetricsService::last7Days());

        $this->assertSame(0, $metrics['done']);
        $this->assertSame(0, $metrics['in_progress']);
        $this->assertSame(0, $metrics['overdue']);
        $this->assertSame(0, $metrics['velocity_week']);
        $this->assertNull($metrics['avg_lead_time_days']);
    }

    #[Test]
    public function it_returns_empty_array_when_no_users(): void
    {
        $result = $this->service->forUsers(collect(), PerformanceMetricsService::last7Days());

        $this->assertSame([], $result);
    }

    #[Test]
    public function it_batches_metrics_for_multiple_users_with_bounded_queries(): void
    {
        $users = collect();
        for ($i = 0; $i < 10; $i++) {
            $u = User::factory()->create();
            Issue::create(['name' => "Done {$i}", 'assignee_id' => $u->id, 'status' => 'done', 'close_date' => now()->subDay()]);
            Issue::create(['name' => "Open {$i}", 'assignee_id' => $u->id, 'status' => 'open']);
            $users->push($u);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $result = $this->service->forUsers($users, PerformanceMetricsService::last7Days());

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(10, $result);
        // 7 aggregate queries: done+lead, in_progress, overdue, velocity, ai_calls, chat_messages, cycle_time
        $this->assertLessThanOrEqual(8, count($queries), 'forUsers should make ≤8 queries regardless of user count');

        foreach ($users as $u) {
            $this->assertSame(1, $result[$u->id]['done']);
            $this->assertSame(1, $result[$u->id]['in_progress']);
        }
    }

    #[Test]
    public function it_aggregates_metrics_for_team(): void
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org']);
        $methodology = Methodology::firstOrCreate(
            ['is_default' => true],
            ['name' => 'Default', 'text' => '', 'scheme_version' => '1'],
        );
        $team = Team::create(['name' => 'Backend', 'slug' => 'backend', 'organization_id' => $org->id, 'methodology_id' => $methodology->id]);
        $u1 = User::factory()->create();
        $u2 = User::factory()->create();
        $team->users()->attach([$u1->id, $u2->id]);

        Issue::create(['name' => 'T1', 'team_id' => $team->id, 'assignee_id' => $u1->id, 'status' => 'done', 'close_date' => now()->subDay()]);
        Issue::create(['name' => 'T2', 'team_id' => $team->id, 'assignee_id' => $u2->id, 'status' => 'open']);

        $teamMetrics = $this->service->forTeam($team, PerformanceMetricsService::last7Days());

        $this->assertSame(1, $teamMetrics['aggregated']['done']);
        $this->assertSame(1, $teamMetrics['aggregated']['in_progress']);
        $this->assertArrayHasKey($u1->id, $teamMetrics['by_member']);
        $this->assertArrayHasKey($u2->id, $teamMetrics['by_member']);
        $this->assertSame(1, $teamMetrics['by_member'][$u1->id]['done']);
        $this->assertSame(1, $teamMetrics['by_member'][$u2->id]['in_progress']);
    }

    #[Test]
    public function it_excludes_soft_deleted_issues(): void
    {
        $issue = Issue::create(['name' => 'Trashed', 'assignee_id' => $this->user->id, 'status' => 'open']);
        $issue->delete();

        $metrics = $this->service->forUser($this->user, PerformanceMetricsService::last7Days());

        $this->assertSame(0, $metrics['in_progress']);
    }

    #[Test]
    public function ai_calls_week_counts_all_agent_activity_logs(): void
    {
        // 3 events within last 7 days, 1 outside
        \Illuminate\Support\Facades\DB::table('agent_activity_logs')->insert([
            ['user_id' => $this->user->id, 'tool_name' => 'create_issue', 'success' => true, 'description' => 'test', 'created_at' => now()->subDay(), ],
            ['user_id' => $this->user->id, 'tool_name' => 'insight_evolved', 'success' => true, 'description' => 'test', 'created_at' => now()->subDays(2), ],
            ['user_id' => $this->user->id, 'tool_name' => 'get_tasks', 'success' => true, 'description' => 'test', 'created_at' => now()->subDays(5), ],
            ['user_id' => $this->user->id, 'tool_name' => 'old', 'success' => true, 'description' => 'test', 'created_at' => now()->subDays(30), ],
        ]);

        $metrics = $this->service->forUser($this->user, PerformanceMetricsService::last7Days());

        $this->assertSame(3, $metrics['ai_calls_week']);
    }

    #[Test]
    public function chat_messages_week_counts_user_role_in_bound_chats_only(): void
    {
        $tgUserId = '99001';
        \App\Models\TelegramUser::create([
            'user_id' => $this->user->id,
            'telegram_user_id' => $tgUserId,
        ]);

        $org = \App\Models\Organization::create(['name' => 'Org', 'slug' => 'org-tg']);
        $boundChat = '-1009999999';
        $unboundChat = '-1008888888';

        // Bound registration
        \Illuminate\Support\Facades\DB::table('telegram_chat_registrations')->insert([
            'telegram_chat_id' => $boundChat,
            'chat_type' => 'supergroup',
            'organization_id' => $org->id,
            'team_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // Unbound registration (organization_id IS NULL)
        \Illuminate\Support\Facades\DB::table('telegram_chat_registrations')->insert([
            'telegram_chat_id' => $unboundChat,
            'chat_type' => 'supergroup',
            'organization_id' => null,
            'team_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $now = now();
        \Illuminate\Support\Facades\DB::table('telegram_chat_messages')->insert([
            // In bound chat, role=user, in window — COUNT
            ['telegram_chat_id' => $boundChat, 'telegram_user_id' => $tgUserId, 'role' => 'user', 'content' => 'a', 'created_at' => $now->copy()->subDay(), 'updated_at' => $now],
            ['telegram_chat_id' => $boundChat, 'telegram_user_id' => $tgUserId, 'role' => 'user', 'content' => 'b', 'created_at' => $now->copy()->subDays(2), 'updated_at' => $now],
            // role=system in bound chat — SKIP
            ['telegram_chat_id' => $boundChat, 'telegram_user_id' => $tgUserId, 'role' => 'system', 'content' => 'c', 'created_at' => $now->copy()->subDay(), 'updated_at' => $now],
            // Unbound chat — SKIP
            ['telegram_chat_id' => $unboundChat, 'telegram_user_id' => $tgUserId, 'role' => 'user', 'content' => 'd', 'created_at' => $now->copy()->subDay(), 'updated_at' => $now],
            // Out of window — SKIP
            ['telegram_chat_id' => $boundChat, 'telegram_user_id' => $tgUserId, 'role' => 'user', 'content' => 'e', 'created_at' => $now->copy()->subDays(30), 'updated_at' => $now],
        ]);

        $metrics = $this->service->forUser($this->user, PerformanceMetricsService::last7Days());

        $this->assertSame(2, $metrics['chat_messages_week']);
    }

    #[Test]
    public function cycle_time_uses_issue_status_history(): void
    {
        // Issue 1: created → in_progress → done. cycle = 1 day.
        $issue1 = \App\Models\Issue::create([
            'name' => 'Cycle 1',
            'assignee_id' => $this->user->id,
            'status' => 'open',
        ]);
        $issue1->update(['status' => 'in_progress']);
        // Simulate history changed_at via direct insert (observer used now())
        \Illuminate\Support\Facades\DB::table('issue_status_histories')->where('issue_id', $issue1->id)->update(['changed_at' => now()->subDays(2)]);
        $issue1->update(['status' => 'done']);
        $issue1->update(['close_date' => now()->subDay()]);

        // Issue 2: no in_progress history (legacy) — should be ignored
        $issue2 = \App\Models\Issue::create([
            'name' => 'Legacy',
            'assignee_id' => $this->user->id,
            'status' => 'done',
            'close_date' => now()->subHours(2),
        ]);

        $metrics = $this->service->forUser($this->user, PerformanceMetricsService::last7Days());

        $this->assertNotNull($metrics['avg_cycle_time_days'], 'should have a cycle time from issue1');
        $this->assertGreaterThan(0.5, $metrics['avg_cycle_time_days']);
        $this->assertLessThan(2.0, $metrics['avg_cycle_time_days']);
    }

    #[Test]
    public function velocity_counts_done_in_last_7_days_regardless_of_period(): void
    {
        // Done 3 days ago — within velocity window
        Issue::create(['name' => 'Recent', 'assignee_id' => $this->user->id, 'status' => 'done', 'close_date' => now()->subDays(3)]);
        // Done 30 days ago — outside velocity window but inside last_30_days period
        Issue::create(['name' => 'Old', 'assignee_id' => $this->user->id, 'status' => 'done', 'close_date' => now()->subDays(30)]);

        $longPeriod = ['from' => now()->subDays(60), 'to' => now()];
        $metrics = $this->service->forUser($this->user, $longPeriod);

        $this->assertSame(2, $metrics['done'], 'done counts across full period');
        $this->assertSame(1, $metrics['velocity_week'], 'velocity always last 7 days');
    }
}
