<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\Issue;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\Agent\Tools\GetMeetingTasksTool;
use App\Services\Agent\Tools\GetOpenIssuesTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'assignee_id' => $user->id,
        ]);

        $this->assertTrue((bool) data_get($result, 'success'));
        $this->assertSame(1, data_get($result, 'tasks_count'));
        $this->assertSame($activeTask->id, data_get($result, 'tasks.0.id'));
        $this->assertNotSame($deletedTask->id, data_get($result, 'tasks.0.id'));
        $this->assertSoftDeleted('issues', ['id' => $deletedTask->id]);
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
