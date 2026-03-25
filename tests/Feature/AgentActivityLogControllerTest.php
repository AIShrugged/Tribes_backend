<?php

namespace Tests\Feature;

use App\Models\AgentActivityLog;
use App\Models\Chat;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AgentActivityLogControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_returns_global_activity_for_authenticated_user_across_all_chats(): void
    {
        $user = User::factory()->create();
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
        $runA = (string) Str::uuid();
        $runB = (string) Str::uuid();

        $chatA = Chat::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'title' => 'Chat A',
        ]);
        $chatB = Chat::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'title' => 'Chat B',
        ]);

        AgentActivityLog::create([
            'user_id' => $user->id,
            'chat_id' => $chatA->id,
            'agent_run_uuid' => $runA,
            'tool_name' => 'create_artifact',
            'description' => 'Created artifact A',
            'success' => true,
            'tool_args' => ['name' => 'A'],
            'tool_result' => ['success' => true],
            'created_at' => now()->subMinute(),
        ]);

        AgentActivityLog::create([
            'user_id' => $user->id,
            'chat_id' => $chatB->id,
            'agent_run_uuid' => $runB,
            'tool_name' => 'update_agent_task',
            'description' => 'Updated task B',
            'success' => true,
            'tool_args' => ['agent_task_id' => 2],
            'tool_result' => ['success' => true],
            'created_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/agent-activity');

        $response->assertOk()
            ->assertJsonPath('data.0.tool_name', 'update_agent_task')
            ->assertJsonPath('data.1.tool_name', 'create_artifact');
    }

    #[Test]
    public function it_can_filter_global_activity_by_agent_run_uuid(): void
    {
        $user = User::factory()->create();
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
        $runA = (string) Str::uuid();
        $runB = (string) Str::uuid();

        $chat = Chat::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'title' => 'Chat',
        ]);

        AgentActivityLog::create([
            'user_id' => $user->id,
            'chat_id' => $chat->id,
            'agent_run_uuid' => $runA,
            'tool_name' => 'create_artifact',
            'description' => 'Created artifact',
            'success' => true,
            'tool_args' => ['name' => 'A'],
            'tool_result' => ['success' => true],
            'created_at' => now(),
        ]);

        AgentActivityLog::create([
            'user_id' => $user->id,
            'chat_id' => $chat->id,
            'agent_run_uuid' => $runB,
            'tool_name' => 'update_agent_task',
            'description' => 'Updated task',
            'success' => true,
            'tool_args' => ['agent_task_id' => 2],
            'tool_result' => ['success' => true],
            'created_at' => now()->addMinute(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/agent-activity?agent_run_uuid=' . $runA);

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.agent_run_uuid', $runA);
    }
}
