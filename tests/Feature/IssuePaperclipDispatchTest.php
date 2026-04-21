<?php

namespace Tests\Feature;

use App\Models\Issue;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IssuePaperclipDispatchTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function development_issue_with_last_paperclip_mode_dispatches_a_paperclip_task(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        [$organization, $team] = $this->createTenantContextFor($user);

        $issue = Issue::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'name' => 'Reopen me',
            'type' => 'development',
            'status' => 'reopen',
            'last_agent_execution_mode' => 'paperclip',
            'agent_task_id' => null,
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/v1/issues/{$issue->id}/dispatch")
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'queued');

        $issue = $issue->fresh();
        $task = \App\Models\AgentTask::findOrFail($issue->agent_task_id);

        $this->assertSame('paperclip', $task->execution_mode?->value);
        $this->assertSame('paperclip', $issue->last_agent_execution_mode);
        $this->assertSame('in_progress', $issue->status);
        $this->assertStringContainsString('last_comment', $task->prompt);
        $this->assertStringContainsString('content_base64', $task->prompt);
        $this->assertNotNull($response->json('data.id'));
        Queue::assertPushed(\App\Jobs\RunAgentTaskJob::class);
    }

    #[Test]
    public function development_issue_with_paperclip_user_id_uses_paperclip_even_if_last_mode_isolated(): void
    {
        Queue::fake();

        $paperclipUser = User::factory()->create();
        [$organization, $team] = $this->createTenantContextFor($paperclipUser);

        $issue = Issue::create([
            'user_id' => $paperclipUser->id,
            'paperclip_user_id' => $paperclipUser->id,
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'name' => 'Reopen me again',
            'type' => 'development',
            'status' => 'reopen',
            'last_agent_execution_mode' => 'isolated',
            'agent_task_id' => null,
        ]);

        $this->actingAs($paperclipUser)
            ->postJson("/api/v1/issues/{$issue->id}/dispatch")
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'queued');

        $issue = $issue->fresh();
        $task = \App\Models\AgentTask::findOrFail($issue->agent_task_id);

        $this->assertSame('paperclip', $task->execution_mode?->value);
        $this->assertSame($paperclipUser->id, $task->user_id);
        $this->assertSame('paperclip', $issue->last_agent_execution_mode);
        $this->assertSame($paperclipUser->id, $issue->paperclip_user_id);
        $this->assertStringContainsString('last_comment', $task->prompt);
        $this->assertStringContainsString('content_base64', $task->prompt);
        Queue::assertPushed(\App\Jobs\RunAgentTaskJob::class);
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
            'name' => 'Paperclip Org',
            'slug' => 'paperclip-org',
        ]);

        $organization->users()->attach($user->id, ['role' => 'manager']);

        $team = Team::create([
            'organization_id' => $organization->id,
            'methodology_id' => $methodology->id,
            'name' => 'Paperclip Team',
            'slug' => 'paperclip-team',
        ]);
        $team->users()->attach($user->id);

        return [$organization, $team];
    }
}
