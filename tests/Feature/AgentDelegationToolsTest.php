<?php

namespace Tests\Feature;

use App\Models\AgentTask;
use App\Models\Issue;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\Agent\AgentToolRegistrar;
use App\Services\Agent\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AgentDelegationToolsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function default_agent_tools_expose_create_issue_and_create_agent_task(): void
    {
        $user = User::factory()->create();
        [$organization, $team] = $this->createTenantContextFor($user);

        $registry = new ToolRegistry;
        $this->app->make(AgentToolRegistrar::class)->registerDefaults(
            $registry,
            $user,
            'web',
            organizationId: $organization->id,
            teamId: $team->id,
        );

        $this->assertNull($registry->get('create_task'));
        $this->assertNotNull($registry->get('create_issue'));
        $this->assertNotNull($registry->get('create_agent_task'));
        $this->assertNotNull($registry->get('regenerate_followup'));

        $issueResult = $registry->get('create_issue')?->execute([
            'name' => 'Prepare release notes',
            'type' => 'task',
            'description' => 'Summarize backend changes for release.',
        ]);

        $this->assertTrue((bool) data_get($issueResult, 'success'));

        $issue = Issue::findOrFail((int) data_get($issueResult, 'issue.id'));
        $this->assertSame($user->id, $issue->user_id);
        $this->assertSame($organization->id, $issue->organization_id);
        $this->assertSame($team->id, $issue->team_id);
        $this->assertSame('task', $issue->type);
        $this->assertSame('open', $issue->status);

        $agentTaskResult = $registry->get('create_agent_task')?->execute([
            'name' => 'Scan repository for regressions',
            'prompt' => 'Inspect the repository and report any risky changes from the last release.',
            'allowed_tools' => ['list_workspaces'],
        ]);

        $this->assertTrue((bool) data_get($agentTaskResult, 'success'));

        $agentTask = AgentTask::findOrFail((int) data_get($agentTaskResult, 'agent_task.id'));
        $this->assertSame($user->id, $agentTask->user_id);
        $this->assertSame($organization->id, $agentTask->organization_id);
        $this->assertSame($team->id, $agentTask->team_id);
        $this->assertSame('one_off', $agentTask->schedule_type->value);
        $this->assertSame(['list_workspaces'], $agentTask->allowed_tools);

        $createAgentTaskSchema = collect($registry->getToolsForLLM())
            ->firstWhere('function.name', 'create_agent_task')['function']['parameters'] ?? null;

        $this->assertSame('string', data_get($createAgentTaskSchema, 'properties.allowed_tools.items.type'));
        $this->assertSame('string', data_get($createAgentTaskSchema, 'properties.allowed_outbound_hosts.items.type'));
    }

    #[Test]
    public function create_agent_task_tool_rejects_missing_tenant_scope_without_context_defaults(): void
    {
        $user = User::factory()->create();
        $registry = new ToolRegistry;

        $this->app->make(AgentToolRegistrar::class)->registerDefaults(
            $registry,
            $user,
            'web',
        );

        $result = $registry->get('create_agent_task')?->execute([
            'name' => 'Detached task',
            'prompt' => 'Try to run without tenant scope.',
        ]);

        $this->assertFalse((bool) data_get($result, 'success'));
        $this->assertSame('Organization binding is required.', data_get($result, 'error'));
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
