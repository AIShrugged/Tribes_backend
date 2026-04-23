<?php

namespace Tests\Feature;

use App\Jobs\RunAgentTaskJob;
use App\Models\AgentTask;
use App\Models\AgentTaskRun;
use App\Models\IssueAgentFlow;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\OrganizationIssueType;
use App\Models\Team;
use App\Models\User;
use App\Services\InlineAgentTaskExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IssueAgentFlowTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function development_issue_dispatch_uses_issue_type_profile_and_repository_context(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        [$organization, $team] = $this->createTenantContextFor($user);

        $profile = \App\Models\AgentProfile::create([
            'key' => 'backend-flow',
            'name' => 'Backend flow',
            'system_prompt' => 'Use repository context and the configured sandbox.',
            'execution_mode' => 'isolated',
            'metadata' => [
                'repository' => [
                    'provider' => 'github',
                    'owner' => 'acme',
                    'repo' => 'api',
                ],
                'notes' => [
                    'source' => 'profile metadata',
                ],
            ],
            'enabled' => true,
        ]);

        OrganizationIssueType::query()
            ->whereNull('organization_id')
            ->where('key', 'backend')
            ->firstOrFail()
            ->update([
                'agent_profile_id' => $profile->id,
            ]);

        $issue = Issue::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'name' => 'Fix login edge case',
            'description' => 'The workflow should split work into sequential agent tasks.',
            'type' => Issue::TYPE_BACKEND,
            'status' => 'open',
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/v1/issues/{$issue->id}/dispatch", [])
            ->assertStatus(201);

        $plannerTask = AgentTask::findOrFail((int) $response->json('data.agent_task_id'));

        $this->assertSame($profile->id, $plannerTask->agent_profile_id);
        $this->assertSame('isolated', $plannerTask->effectiveExecutionMode()->value);
        $this->assertSame($profile->metadata, data_get($plannerTask->metadata, 'profile_metadata'));
        $this->assertSame($profile->metadata, data_get($plannerTask->input_payload, 'profile_metadata'));
    }

    #[Test]
    public function development_issue_flow_plans_steps_blocks_on_failure_and_resumes_after_retry(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        [$organization, $team] = $this->createTenantContextFor($user);

        $issue = Issue::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'name' => 'Fix login edge case',
            'description' => 'The workflow should split work into sequential agent tasks.',
            'type' => Issue::TYPE_BACKEND,
            'status' => 'open',
        ]);

        $dispatchResponse = $this->actingAs($user)
            ->postJson("/api/v1/issues/{$issue->id}/dispatch", [])
            ->assertStatus(201);

        $plannerTaskId = $dispatchResponse->json('data.agent_task_id');
        $plannerRunId = $dispatchResponse->json('data.id');

        $this->assertNotNull($plannerTaskId);
        $this->assertNotNull($plannerRunId);

        $plannerJob = new RunAgentTaskJob((int) $plannerTaskId, (int) $plannerRunId, 3);
        $this->app->call([$plannerJob, 'handle']);

        $issue->refresh();
        $flow = IssueAgentFlow::query()
            ->where('issue_id', $issue->id)
            ->with('steps.agentTask.runs')
            ->firstOrFail();

        $this->assertSame('running', $flow->status->value);
        $this->assertCount(3, $flow->steps);

        $planningStep = $flow->steps->first(fn ($step) => $step->kind?->value === 'planning');
        $firstStep = $flow->steps->first(fn ($step) => $step->position === 1);
        $secondStep = $flow->steps->first(fn ($step) => $step->position === 2);

        $this->assertNotNull($planningStep);
        $this->assertSame('succeeded', $planningStep->status->value);
        $this->assertSame('queued', $firstStep->status->value);
        $this->assertSame('pending', $secondStep->status->value);
        $this->assertNotNull($firstStep->agent_task_id);
        $this->assertNull($secondStep->agent_task_id);

        $this->assertNotEmpty($planningStep->output);

        $firstTask = AgentTask::findOrFail($firstStep->agent_task_id);
        $firstRun = AgentTaskRun::query()->where('agent_task_id', $firstTask->id)->latest('id')->firstOrFail();

        $executionCalls = 0;
        $this->app->instance(InlineAgentTaskExecutor::class, Mockery::mock(InlineAgentTaskExecutor::class, function ($mock) use (&$executionCalls): void {
            $mock->shouldReceive('execute')->andReturnUsing(function () use (&$executionCalls): string {
                $executionCalls++;

                if ($executionCalls === 1) {
                    throw new \RuntimeException('step one failed');
                }

                return 'Mocked testing response';
            });
        }));

        $failingJob = new RunAgentTaskJob($firstTask->id, $firstRun->id, 3);

        try {
            $this->app->call([$failingJob, 'handle']);
            $this->fail('Expected the first execution attempt to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('step one failed', $exception->getMessage());
            $failingJob->failed($exception);
        }

        $flow->refresh();
        $firstStep->refresh();
        $secondStep->refresh();

        $this->assertSame('blocked', $flow->status->value);
        $this->assertSame('failed', $firstStep->status->value);
        $this->assertSame('pending', $secondStep->status->value);

        $retryJob = new RunAgentTaskJob($firstTask->id, $firstRun->id, 3);
        $this->app->call([$retryJob, 'handle']);

        $flow->refresh();
        $firstStep->refresh();
        $secondStep->refresh();

        $this->assertSame('running', $flow->status->value);
        $this->assertSame('succeeded', $firstStep->status->value);
        $this->assertSame('queued', $secondStep->status->value);
        $this->assertNotNull($secondStep->agent_task_id);

        $secondTask = AgentTask::findOrFail($secondStep->agent_task_id);
        $secondRun = AgentTaskRun::query()->where('agent_task_id', $secondTask->id)->latest('id')->firstOrFail();

        $finalJob = new RunAgentTaskJob($secondTask->id, $secondRun->id, 3);
        $this->app->call([$finalJob, 'handle']);

        $flow->refresh();
        $secondStep->refresh();

        $this->assertSame('completed', $flow->status->value);
        $this->assertSame('succeeded', $secondStep->status->value);
        $this->assertSame($secondTask->id, $issue->fresh()->agent_task_id);
    }

    private function createTenantContextFor(User $user): array
    {
        $organization = Organization::create([
            'name' => 'Flow Organization',
            'slug' => 'flow-organization',
        ]);

        $organization->users()->attach($user->id, ['role' => 'manager']);

        $team = Team::create([
            'organization_id' => $organization->id,
            'name' => 'Flow Team',
            'slug' => 'flow-team',
            'methodology_id' => \App\Models\Methodology::query()->where('is_default', true)->value('id')
                ?? \App\Models\Methodology::create([
                    'name' => 'Default Methodology',
                    'text' => 'Default methodology text',
                    'scheme' => '{}',
                    'is_default' => true,
                ])->id,
        ]);

        $team->users()->attach($user->id);

        return [$organization, $team];
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
