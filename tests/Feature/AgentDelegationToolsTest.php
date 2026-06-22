<?php

namespace Tests\Feature;

use App\Models\AgentTask;
use App\Models\Issue;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\OrganizationContext;
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
    public function default_agent_tools_expose_create_entity_for_issue_and_agent_task(): void
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
        $this->assertNotNull($registry->get('create_entity'));

        $createEntitySchema = collect($registry->getToolsForLLM())
            ->firstWhere('function.name', 'create_entity')['function']['parameters'] ?? null;

        $this->assertContains('issue', data_get($createEntitySchema, 'properties.entity.enum'));
        $this->assertContains('agent_task', data_get($createEntitySchema, 'properties.entity.enum'));
        $this->assertContains('followup', data_get($createEntitySchema, 'properties.entity.enum'));

        $issueResult = $registry->get('create_entity')?->execute([
            'entity' => 'issue',
            'data' => [
                'name' => 'Prepare release notes',
                'type' => 'organization',
                'description' => 'Summarize backend changes for release.',
            ],
        ]);

        $this->assertTrue((bool) data_get($issueResult, 'success'));

        $issue = Issue::findOrFail((int) data_get($issueResult, 'issue.id'));
        $this->assertSame($user->id, $issue->user_id);
        $this->assertSame($organization->id, $issue->organization_id);
        $this->assertSame($team->id, $issue->team_id);
        $this->assertSame('organization', $issue->type);
        $this->assertSame('open', $issue->status);

        $agentTaskResult = $registry->get('create_entity')?->execute([
            'entity' => 'agent_task',
            'data' => [
                'name' => 'Scan repository for regressions',
                'prompt' => 'Inspect the repository and report any risky changes from the last release.',
                'allowed_tools' => ['list_workspaces'],
            ],
        ]);

        $this->assertTrue((bool) data_get($agentTaskResult, 'success'));

        $agentTask = AgentTask::findOrFail((int) data_get($agentTaskResult, 'agent_task.id'));
        $this->assertSame($user->id, $agentTask->user_id);
        $this->assertSame($organization->id, $agentTask->organization_id);
        $this->assertSame($team->id, $agentTask->team_id);
        $this->assertSame('one_off', $agentTask->schedule_type->value);
        $this->assertSame(['list_workspaces'], $agentTask->allowed_tools);

        $this->assertSame('string', data_get($createEntitySchema, 'properties.data.properties.allowed_tools.items.type'));
        $this->assertSame('string', data_get($createEntitySchema, 'properties.data.properties.allowed_outbound_hosts.items.type'));
    }

    #[Test]
    public function create_entity_tool_rejects_agent_task_missing_tenant_scope_without_context_defaults(): void
    {
        $user = User::factory()->create();
        $registry = new ToolRegistry;

        $this->app->make(AgentToolRegistrar::class)->registerDefaults(
            $registry,
            $user,
            'web',
        );

        $result = $registry->get('create_entity')?->execute([
            'entity' => 'agent_task',
            'data' => [
                'name' => 'Detached task',
                'prompt' => 'Try to run without tenant scope.',
            ],
        ]);

        $this->assertFalse((bool) data_get($result, 'success'));
        $this->assertSame('Organization binding is required.', data_get($result, 'error'));
    }

    #[Test]
    public function update_entity_tool_can_bind_unscoped_issue_to_current_team(): void
    {
        $user = User::factory()->create();
        [$organization, $team] = $this->createTenantContextFor($user);

        $issue = Issue::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'team_id' => null,
            'name' => 'Automate Advertisement Verification',
            'description' => 'Detect duplicate listings and broken images.',
            'type' => 'epic',
            'status' => 'open',
        ]);

        $registry = new ToolRegistry;
        $this->app->make(AgentToolRegistrar::class)->registerDefaults(
            $registry,
            $user,
            'web',
            organizationId: $organization->id,
            teamId: $team->id,
        );

        $result = $registry->get('update_entity')?->execute([
            'entity' => 'issue',
            'id' => $issue->id,
            'data' => [
                'team_id' => $team->id,
            ],
        ]);

        $this->assertTrue((bool) data_get($result, 'success'), json_encode($result));
        $this->assertSame($team->id, data_get($result, 'issue.team_id'));
        $this->assertDatabaseHas('issues', [
            'id' => $issue->id,
            'team_id' => $team->id,
        ]);
    }

    #[Test]
    public function update_entity_tool_can_update_indexed_organization_context_chunk(): void
    {
        $user = User::factory()->create();
        [$organization, $team] = $this->createTenantContextFor($user);

        $context = OrganizationContext::create([
            'organization_id' => $organization->id,
            'source_type' => Organization::class,
            'source_id' => $organization->id,
            'text' => 'Old indexed context.',
            'indexed_at' => now()->subDay(),
        ]);

        $registry = new ToolRegistry;
        $this->app->make(AgentToolRegistrar::class)->registerDefaults(
            $registry,
            $user,
            'web',
            organizationId: $organization->id,
            teamId: $team->id,
        );

        $schema = collect($registry->getToolsForLLM())
            ->firstWhere('function.name', 'update_entity')['function']['parameters'] ?? [];

        $this->assertContains('organization_context', data_get($schema, 'properties.entity.enum'));

        $result = $registry->get('update_entity')?->execute([
            'entity' => 'organization_context',
            'id' => $context->id,
            'data' => [
                'text' => 'Updated indexed context with current product positioning.',
            ],
        ]);

        $this->assertTrue((bool) data_get($result, 'success'), json_encode($result));
        $this->assertSame(
            'Updated indexed context with current product positioning.',
            data_get($result, 'organization_context.text'),
        );
        $this->assertDatabaseHas('organization_contexts', [
            'id' => $context->id,
            'text' => 'Updated indexed context with current product positioning.',
        ]);
    }

    #[Test]
    public function query_db_tasks_cannot_escape_the_conversation_organization_scope(): void
    {
        $user = User::factory()->create();
        [$organization, $team] = $this->createTenantContextFor($user);

        $foreignOrganization = Organization::create([
            'name' => 'Foreign',
            'slug' => 'foreign',
        ]);

        Issue::create([
            'name' => 'Scoped onboarding task',
            'description' => 'Visible in the current organization.',
            'status' => 'open',
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'team_id' => $team->id,
        ]);

        Issue::create([
            'name' => 'Foreign onboarding task',
            'description' => 'Must not leak into the current organization.',
            'status' => 'open',
            'organization_id' => $foreignOrganization->id,
        ]);

        // query_db is no longer registered on the agent surface (Stage 1 cutover to
        // query_data). QueryTribesDataTool remains the fallback/MCP read tool, so this
        // exercises it directly with the same conversation org/team scope.
        $tool = new \App\Services\Agent\Tools\QueryTribesDataTool(
            $user,
            $this->app->make(\App\Services\AgentMemoryLookupService::class),
            $organization->id,
            $team->id,
        );

        $result = $tool->execute([
            'entity' => 'tasks',
            'filters' => [
                'organization_id' => $foreignOrganization->id,
                'statuses' => 'open',
            ],
            'limit' => 10,
        ]);

        $this->assertTrue((bool) data_get($result, 'success'));
        $this->assertSame(1, data_get($result, 'tasks_count'));
        $this->assertSame('Scoped onboarding task', data_get($result, 'tasks.0.name'));
        $this->assertSame($organization->id, data_get($result, 'tasks.0.organization_id'));
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
