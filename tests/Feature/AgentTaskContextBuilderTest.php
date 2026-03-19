<?php

namespace Tests\Feature;

use App\Models\AgentMemory;
use App\Models\AgentProfile;
use App\Models\AgentTask;
use App\Models\AgentTaskRun;
use App\Models\User;
use App\Services\AgentTaskContextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AgentTaskContextBuilderTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_builds_context_from_profile_memory_and_payload(): void
    {
        $user = User::factory()->create();
        $profile = AgentProfile::create([
            'key' => 'github-reviewer',
            'name' => 'GitHub Reviewer',
            'system_prompt' => 'Review pull requests with correctness-first priority.',
            'execution_mode' => 'isolated',
            'allowed_tools' => ['get_current_user'],
            'allowed_outbound_hosts' => ['api.github.com', '*.github.com'],
        ]);

        $task = AgentTask::create([
            'user_id' => $user->id,
            'agent_profile_id' => $profile->id,
            'name' => 'Review PR',
            'prompt' => 'Review pull request #182.',
            'schedule_type' => 'one_off',
            'next_run_at' => now(),
            'input_payload' => [
                'provider' => 'github',
                'owner' => 'acme',
                'repo' => 'api',
                'pr_number' => 182,
            ],
            'enabled' => true,
        ]);

        AgentMemory::create([
            'agent_profile_id' => $profile->id,
            'scope_type' => 'profile',
            'kind' => 'instruction',
            'content' => 'Always check tests and migrations first.',
            'priority' => 100,
        ]);

        AgentMemory::create([
            'agent_profile_id' => $profile->id,
            'scope_type' => 'repository',
            'scope_key' => 'github:acme/api',
            'kind' => 'fact',
            'content' => 'This repository uses PHPUnit and Pint.',
            'priority' => 80,
        ]);

        $context = $this->app->make(AgentTaskContextBuilder::class)->build($task->fresh());

        $this->assertSame('isolated', $context['execution_mode']);
        $this->assertSame(['get_current_user'], $context['allowed_tools']);
        $this->assertSame(['api.github.com', '*.github.com'], $context['allowed_outbound_hosts']);
        $this->assertStringContainsString('Review pull requests with correctness-first priority.', $context['system_prompt_extension']);
        $this->assertStringContainsString('Always check tests and migrations first.', $context['system_prompt_extension']);
        $this->assertStringContainsString('This repository uses PHPUnit and Pint.', $context['system_prompt_extension']);
        $this->assertStringContainsString('Review pull request #182.', $context['user_prompt']);
        $this->assertStringContainsString('"owner": "acme"', $context['user_prompt']);
        $this->assertStringContainsString('"repo": "api"', $context['user_prompt']);
    }

    #[Test]
    public function task_level_outbound_hosts_override_profile_defaults(): void
    {
        $user = User::factory()->create();
        $profile = AgentProfile::create([
            'key' => 'github-reviewer',
            'name' => 'GitHub Reviewer',
            'execution_mode' => 'isolated',
            'allowed_outbound_hosts' => ['api.github.com'],
        ]);

        $task = AgentTask::create([
            'user_id' => $user->id,
            'agent_profile_id' => $profile->id,
            'name' => 'Review PR',
            'prompt' => 'Review pull request #182.',
            'schedule_type' => 'one_off',
            'next_run_at' => now(),
            'allowed_outbound_hosts' => ['raw.githubusercontent.com'],
            'enabled' => true,
        ]);

        $context = $this->app->make(AgentTaskContextBuilder::class)->build($task->fresh());

        $this->assertSame(['raw.githubusercontent.com'], $context['allowed_outbound_hosts']);
    }

    #[Test]
    public function it_includes_followup_policy_and_lineage_for_followup_tasks(): void
    {
        $user = User::factory()->create();

        $parentTask = AgentTask::create([
            'user_id' => $user->id,
            'name' => 'Scan workspace',
            'prompt' => 'Scan user workspace and decide next action.',
            'schedule_type' => 'one_off',
            'execution_mode' => 'isolated',
            'next_run_at' => now(),
            'enabled' => true,
        ]);

        $originRun = AgentTaskRun::create([
            'agent_task_id' => $parentTask->id,
            'status' => 'processing',
            'started_at' => now(),
        ]);

        $task = AgentTask::create([
            'user_id' => $user->id,
            'parent_agent_task_id' => $parentTask->id,
            'origin_agent_task_run_id' => $originRun->id,
            'followup_depth' => 1,
            'name' => 'Create reminder',
            'prompt' => 'Create a reminder for the user.',
            'schedule_type' => 'one_off',
            'execution_mode' => 'isolated',
            'next_run_at' => now(),
            'enabled' => true,
            'metadata' => [
                'followup' => [
                    'created_from_task_id' => $parentTask->id,
                    'created_from_run_id' => $originRun->id,
                    'context_summary' => 'The workspace scan found one pending action that should become a user reminder.',
                ],
            ],
        ]);

        $context = $this->app->make(AgentTaskContextBuilder::class)->build($task->fresh());

        $this->assertTrue($context['followup_policy']['prefer_small_bounded_tasks']);
        $this->assertGreaterThan(0, $context['followup_policy']['max_depth']);
        $this->assertGreaterThan(0, $context['followup_policy']['max_per_run']);
        $this->assertSame($task->id, $context['task_lineage']['task_id']);
        $this->assertSame($parentTask->id, $context['task_lineage']['parent_task_id']);
        $this->assertSame($originRun->id, $context['task_lineage']['origin_run_id']);
        $this->assertSame(1, $context['task_lineage']['followup_depth']);
        $this->assertStringContainsString('pending action', $context['task_lineage']['inherited_context_summary']);
    }
}
