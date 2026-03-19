<?php

namespace Tests\Feature;

use App\Jobs\RunAgentTaskJob;
use App\Models\AgentTask;
use App\Models\AgentTaskRun;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePermission;
use App\Services\AgentMemoryIngestionService;
use App\Services\AgentTaskContextBuilder;
use App\Services\AgentTaskFollowupService;
use App\Services\AgentTaskRunTokenService;
use App\Services\AgentTaskToolExecutor;
use App\Services\InlineAgentTaskExecutor;
use App\Services\IsolatedAgentTaskExecutor;
use App\Services\Workspace\WorkspaceAccessService;
use App\Services\Workspace\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IsolatedAgentTaskExecutionFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'workspaces.disk' => 'local',
            'agent.agent_tasks.sandbox_internal_base_url' => 'http://app',
        ]);

        Storage::fake('local');
    }

    #[Test]
    public function isolated_run_can_create_followup_and_persist_plan_handoff_and_workspace_changes(): void
    {
        [$user, $workspace] = $this->createWorkspaceContext();

        Storage::disk('local')->put($workspace->root_prefix.'/docs/plan.txt', 'initial workspace scan target');

        $task = AgentTask::create([
            'user_id' => $user->id,
            'name' => 'Scan workspace and split next step',
            'prompt' => 'Inspect the workspace, then split reminder or PR work into a follow-up task.',
            'schedule_type' => 'one_off',
            'execution_mode' => 'isolated',
            'agent_task_type' => 'background',
            'output_mode' => 'plain',
            'allowed_tools' => [
                'list_workspaces',
                'read_workspace_file',
                'write_workspace_file',
                'create_followup_agent_task',
            ],
            'next_run_at' => now()->subMinute(),
            'enabled' => true,
            'locked_at' => now(),
            'max_attempts' => 1,
            'metadata' => ['max_iterations' => 6],
        ]);

        $run = AgentTaskRun::create([
            'agent_task_id' => $task->id,
            'status' => 'queued',
            'scheduled_for' => now()->subMinute(),
        ]);

        $this->app->instance(IsolatedAgentTaskExecutor::class, new class(
            $this->app->make(AgentTaskRunTokenService::class),
            $this->app->make(AgentTaskToolExecutor::class),
            $this->app->make(AgentTaskContextBuilder::class),
            $this->app->make(AgentMemoryIngestionService::class),
            $this->app->make(WorkspaceAccessService::class),
            $this->app->make(WorkspaceService::class),
            $this->app->make(AgentTaskFollowupService::class),
        ) extends IsolatedAgentTaskExecutor
        {
            public function __construct(
                AgentTaskRunTokenService $runTokenService,
                AgentTaskToolExecutor $toolExecutor,
                AgentTaskContextBuilder $contextBuilder,
                AgentMemoryIngestionService $memoryIngestionService,
                WorkspaceAccessService $workspaceAccessService,
                WorkspaceService $workspaceService,
                private readonly AgentTaskFollowupService $followupService,
            ) {
                parent::__construct(
                    $runTokenService,
                    $toolExecutor,
                    $contextBuilder,
                    $memoryIngestionService,
                    $workspaceAccessService,
                    $workspaceService,
                );
            }

            protected function runSandboxProcess(AgentTask $task, AgentTaskRun $run, string $mountWorkspace, ?array $persistentWorkspace = null): array
            {
                $workspace = storage_path('app/private/sandbox-runs/'.$run->id);
                $payload = json_decode((string) File::get($workspace.'/input/task.json'), true);

                if (! is_array($payload)) {
                    throw new \RuntimeException('Sandbox payload was not written before process execution.');
                }

                if (! data_get($payload, 'followup_policy.prefer_small_bounded_tasks')) {
                    throw new \RuntimeException('Sandbox payload is missing follow-up policy.');
                }

                if ((int) data_get($payload, 'task.lineage.followup_depth', -1) !== 0) {
                    throw new \RuntimeException('Unexpected follow-up depth for parent task.');
                }

                $localWorkspacePath = data_get($payload, 'workspaces.0.local_path');
                if (! is_string($localWorkspacePath) || $localWorkspacePath === '') {
                    throw new \RuntimeException('Materialized workspace local path is missing from payload.');
                }

                File::ensureDirectoryExists(dirname($localWorkspacePath.'/notes/scan.txt'));
                File::put($localWorkspacePath.'/notes/scan.txt', "scan-complete\nnext-step=create-pr");

                $followupTask = $this->followupService->createFollowupTask($task, $run, [
                    'name' => 'Create PR for workspace changes',
                    'prompt' => 'Create the PR for the prepared workspace changes and summarize it.',
                    'context_summary' => 'Workspace scan is complete. The next bounded step is to create the PR for the prepared changes.',
                    'allowed_tools' => ['list_workspaces', 'read_workspace_file'],
                    'delay_seconds' => 60,
                ]);

                File::ensureDirectoryExists($workspace.'/output');
                File::put($workspace.'/output/result.json', json_encode([
                    'success' => true,
                    'output' => 'Workspace scan completed and a follow-up PR task was created.',
                    'summary' => 'Completed the scan and handed off PR creation.',
                    'blocker' => null,
                    'actions' => [
                        [
                            'type' => 'scan_workspace',
                            'title' => 'Scanned user workspace',
                            'status' => 'completed',
                        ],
                    ],
                    'findings' => [
                        'The workspace contains a change set that should be turned into a PR in a separate run.',
                    ],
                    'artifacts' => [],
                    'memory_candidates' => [],
                    'plan' => [
                        'Scan the writable workspace',
                        'Persist scan notes',
                        'Create a narrow follow-up task for PR creation',
                    ],
                    'handoff' => [
                        'reason' => 'PR creation is a separate bounded action and should run in its own task.',
                        'target' => 'followup_task',
                        'context_summary' => 'Workspace scan completed. Use the prepared notes and create the PR.',
                        'status' => 'created',
                        'followup_task_id' => $followupTask->id,
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                return [
                    'successful' => true,
                    'stdout' => 'simulated sandbox run',
                    'stderr' => '',
                    'exit_code' => 0,
                ];
            }
        });

        $job = new RunAgentTaskJob($task->id, $run->id, 1);
        $this->app->call([$job, 'handle'], [
            'inlineExecutor' => $this->app->make(InlineAgentTaskExecutor::class),
            'isolatedExecutor' => $this->app->make(IsolatedAgentTaskExecutor::class),
        ]);

        $task = $task->fresh();
        $run = $run->fresh();
        $followupTask = AgentTask::query()->where('parent_agent_task_id', $task->id)->firstOrFail();

        $this->assertSame('completed', $run->status->value);
        $this->assertSame('Workspace scan completed and a follow-up PR task was created.', $run->output);
        $this->assertFalse($task->enabled);
        $this->assertNull($task->next_run_at);
        $this->assertSame([
            'Scan the writable workspace',
            'Persist scan notes',
            'Create a narrow follow-up task for PR creation',
        ], data_get($run->metadata, 'sandbox_result.plan'));
        $this->assertSame('followup_task', data_get($run->metadata, 'sandbox_result.handoff.target'));
        $this->assertSame($followupTask->id, data_get($run->metadata, 'sandbox_result.handoff.followup_task_id'));
        $this->assertSame($task->id, $followupTask->parent_agent_task_id);
        $this->assertSame($run->id, $followupTask->origin_agent_task_run_id);
        $this->assertSame(1, $followupTask->followup_depth);
        $this->assertStringContainsString('## Follow-up Context', $followupTask->prompt);
        Storage::disk('local')->assertExists($workspace->root_prefix.'/notes/scan.txt');
        $this->assertSame(
            "scan-complete\nnext-step=create-pr",
            Storage::disk('local')->get($workspace->root_prefix.'/notes/scan.txt')
        );
    }

    private function createWorkspaceContext(): array
    {
        $methodology = Methodology::query()->where('is_default', true)->first()
            ?? Methodology::create([
                'name' => 'Default Methodology',
                'text' => 'Default methodology text',
                'scheme' => '{}',
                'is_default' => true,
            ]);

        $organization = Organization::create([
            'name' => 'Executor Org',
            'slug' => 'executor-org',
        ]);

        $team = Team::create([
            'organization_id' => $organization->id,
            'methodology_id' => $methodology->id,
            'name' => 'Executor Team',
            'slug' => 'executor-team',
        ]);

        $user = User::factory()->create();
        $organization->users()->attach($user->id, ['role' => 'employee']);
        $team->users()->attach($user->id);

        $workspace = Workspace::create([
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'owner_user_id' => null,
            'name' => 'Execution Workspace',
            'slug' => 'execution-workspace',
            'scope_type' => 'user_team_private',
            'root_prefix' => 'workspaces/orgs/'.$organization->id.'/teams/'.$team->id.'/users/execution-workspace',
            'storage_disk' => 'local',
            'status' => 'active',
        ]);

        WorkspacePermission::create([
            'workspace_id' => $workspace->id,
            'principal_type' => 'user',
            'principal_id' => (string) $user->id,
            'can_list' => true,
            'can_read' => true,
            'can_write' => true,
            'can_delete' => true,
            'can_execute' => true,
            'can_admin' => false,
        ]);

        return [$user, $workspace];
    }
}
