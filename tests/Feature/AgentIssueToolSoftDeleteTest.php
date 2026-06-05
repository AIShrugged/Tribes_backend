<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\Issue;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\Agent\Tools\QueryTribesDataTool;
use App\Services\AgentMemoryLookupService;
use App\Services\Agent\Tools\GetMeetingTasksTool;
use App\Services\Agent\Tools\GetOpenIssuesTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AgentIssueToolSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function get_open_issues_ignores_soft_deleted_issues(): void
    {
        $user = User::factory()->create();
        [$organization, $team] = $this->createTenantContextFor($user);

        $activeIssue = Issue::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'name' => 'Active task',
            'type' => 'organization',
            'status' => 'open',
        ]);

        $deletedIssue = Issue::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'name' => 'Deleted task',
            'type' => 'organization',
            'status' => 'open',
        ]);
        $deletedIssue->delete();

        $tool = $this->app->make(GetOpenIssuesTool::class);
        $result = $tool->execute([
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'statuses' => 'open,in_progress',
        ]);

        $this->assertTrue((bool) data_get($result, 'success'));
        $this->assertSame(1, data_get($result, 'issues_count'));
        $this->assertSame($activeIssue->id, data_get($result, 'issues.0.id'));
        $this->assertNotSame($deletedIssue->id, data_get($result, 'issues.0.id'));
        $this->assertSoftDeleted('issues', ['id' => $deletedIssue->id]);
    }

    #[Test]
    public function get_tasks_ignores_soft_deleted_issues(): void
    {
        $user = User::factory()->create();
        [$organization, $team] = $this->createTenantContextFor($user);

        $event = CalendarEvent::create([
            'source_id' => 1,
            'external_id' => null,
            'platform' => 'google_calendar',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'url' => 'https://example.com/meeting',
            'title' => 'Planning',
            'description' => 'Sprint planning',
            'required_bot' => false,
            'bot_id' => null,
        ]);

        $activeTask = Issue::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'sourceable_type' => CalendarEvent::class,
            'sourceable_id' => $event->id,
            'assignee_id' => $user->id,
            'name' => 'Active meeting task',
            'type' => 'organization',
            'status' => 'open',
        ]);

        $deletedTask = Issue::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'sourceable_type' => CalendarEvent::class,
            'sourceable_id' => $event->id,
            'assignee_id' => $user->id,
            'name' => 'Deleted meeting task',
            'type' => 'organization',
            'status' => 'in_progress',
        ]);
        $deletedTask->delete();

        $tool = $this->app->make(GetMeetingTasksTool::class);
        $result = $tool->execute([
            'calendar_event_id' => $event->id,
            'organization_id' => $organization->id,
            'assignee_id' => $user->id,
        ]);

        $this->assertTrue((bool) data_get($result, 'success'));
        $this->assertSame(1, data_get($result, 'tasks_count'));
        $this->assertSame($activeTask->id, data_get($result, 'tasks.0.id'));
        $this->assertNotSame($deletedTask->id, data_get($result, 'tasks.0.id'));
        $this->assertSoftDeleted('issues', ['id' => $deletedTask->id]);
    }

    #[Test]
    public function task_tools_reject_queries_without_tenant_scope(): void
    {
        $openIssues = $this->app->make(GetOpenIssuesTool::class);
        $openResult = $openIssues->execute([]);

        $tasks = $this->app->make(GetMeetingTasksTool::class);
        $tasksResult = $tasks->execute([]);

        $this->assertFalse((bool) data_get($openResult, 'success'));
        $this->assertSame('organization_id or team_id is required for task queries.', data_get($openResult, 'error'));
        $this->assertFalse((bool) data_get($tasksResult, 'success'));
        $this->assertSame('organization_id or team_id is required for task queries.', data_get($tasksResult, 'error'));
    }

    #[Test]
    public function scoped_task_tools_reject_foreign_organization(): void
    {
        $user = User::factory()->create();
        [$organization] = $this->createTenantContextFor($user);
        $foreignOrganization = Organization::create([
            'name' => 'Foreign',
            'slug' => 'foreign',
        ]);

        $tool = new GetOpenIssuesTool($user, $organization->id);
        $result = $tool->execute(['organization_id' => $foreignOrganization->id]);

        $this->assertFalse((bool) data_get($result, 'success'));
        $this->assertSame('You do not have access to this organization.', data_get($result, 'error'));
    }

    #[Test]
    public function query_db_tasks_requires_tenant_scope_and_rejects_foreign_organization(): void
    {
        $user = User::factory()->create();
        [$organization] = $this->createTenantContextFor($user);
        $foreignOrganization = Organization::create([
            'name' => 'Foreign Query',
            'slug' => 'foreign-query',
        ]);

        $tool = new QueryTribesDataTool($user, $this->app->make(AgentMemoryLookupService::class));

        $missingScope = $tool->execute(['entity' => 'tasks']);
        $foreignScope = $tool->execute([
            'entity' => 'tasks',
            'filters' => ['organization_id' => $foreignOrganization->id],
        ]);
        $ownScope = $tool->execute([
            'entity' => 'tasks',
            'filters' => ['organization_id' => $organization->id],
        ]);

        $this->assertFalse((bool) data_get($missingScope, 'success'));
        $this->assertSame('organization_id or team_id is required for task queries.', data_get($missingScope, 'error'));
        $this->assertFalse((bool) data_get($foreignScope, 'success'));
        $this->assertSame('You do not have access to this organization.', data_get($foreignScope, 'error'));
        $this->assertTrue((bool) data_get($ownScope, 'success'));
    }

    #[Test]
    public function task_tools_filter_by_created_date(): void
    {
        $user = User::factory()->create();
        [$organization, $team] = $this->createTenantContextFor($user);

        $oldTask = Issue::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'name' => 'Old task',
            'type' => 'organization',
            'status' => 'open',
        ]);
        $oldTask->forceFill(['created_at' => Carbon::parse('2026-01-10 12:00:00')])->save();

        $recentTask = Issue::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'name' => 'Recent task',
            'type' => 'organization',
            'status' => 'open',
        ]);
        $recentTask->forceFill(['created_at' => Carbon::parse('2026-01-20 12:00:00')])->save();

        $tasksTool = $this->app->make(GetMeetingTasksTool::class);
        $tasksResult = $tasksTool->execute([
            'organization_id' => $organization->id,
            'created_after' => '2026-01-15',
        ]);

        $openIssuesTool = $this->app->make(GetOpenIssuesTool::class);
        $openIssuesResult = $openIssuesTool->execute([
            'organization_id' => $organization->id,
            'created_before' => '2026-01-15',
        ]);

        $this->assertTrue((bool) data_get($tasksResult, 'success'));
        $this->assertSame(1, data_get($tasksResult, 'tasks_count'));
        $this->assertSame($recentTask->id, data_get($tasksResult, 'tasks.0.id'));

        $this->assertTrue((bool) data_get($openIssuesResult, 'success'));
        $this->assertSame(1, data_get($openIssuesResult, 'issues_count'));
        $this->assertSame($oldTask->id, data_get($openIssuesResult, 'issues.0.id'));
    }

    #[Test]
    public function query_db_tasks_filters_by_created_date(): void
    {
        $user = User::factory()->create();
        [$organization, $team] = $this->createTenantContextFor($user);

        $oldTask = Issue::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'name' => 'Old query task',
            'type' => 'organization',
            'status' => 'open',
        ]);
        $oldTask->forceFill(['created_at' => Carbon::parse('2026-02-10 12:00:00')])->save();

        $recentTask = Issue::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'name' => 'Recent query task',
            'type' => 'organization',
            'status' => 'open',
        ]);
        $recentTask->forceFill(['created_at' => Carbon::parse('2026-02-20 12:00:00')])->save();

        $tool = new QueryTribesDataTool($user, $this->app->make(AgentMemoryLookupService::class));
        $result = $tool->execute([
            'entity' => 'tasks',
            'filters' => [
                'organization_id' => $organization->id,
                'created_after' => '2026-02-15',
            ],
        ]);

        $this->assertTrue((bool) data_get($result, 'success'));
        $this->assertSame(1, data_get($result, 'tasks_count'));
        $this->assertSame($recentTask->id, data_get($result, 'tasks.0.id'));
        $this->assertNotSame($oldTask->id, data_get($result, 'tasks.0.id'));
    }

    private function createTenantContextFor(User $user): array
    {
        $methodology = Methodology::query()->where('is_default', true)->first()
            ?? Methodology::create([
                'name' => 'Default Methodology',
                'text' => 'Default methodology text',
                'scheme' => '{}',
                'is_default' => true,
            ]);

        $organization = Organization::create([
            'name' => 'Acme',
            'slug' => 'acme',
        ]);

        $team = Team::create([
            'organization_id' => $organization->id,
            'methodology_id' => $methodology->id,
            'name' => 'Platform',
            'slug' => 'platform',
        ]);

        $organization->users()->attach($user->id, ['role' => 'manager']);
        $team->users()->attach($user->id);

        return [$organization, $team];
    }
}
