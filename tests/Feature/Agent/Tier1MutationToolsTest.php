<?php

namespace Tests\Feature\Agent;

use App\Models\Issue;
use App\Models\Organization;
use App\Models\User;
use App\Services\Agent\Tools\Contracts\HighImpactAgentTool;
use App\Services\Agent\Tools\ReassignTaskTool;
use App\Services\Agent\Tools\UpdateTaskFieldsTool;
use App\Services\Commands\CommandRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class Tier1MutationToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
    }

    #[Test]
    public function reassign_and_update_tools_are_high_impact(): void
    {
        [$userA] = $this->userInOrg('A');
        $this->assertInstanceOf(HighImpactAgentTool::class, new ReassignTaskTool($userA, new CommandRunner));
        $this->assertInstanceOf(HighImpactAgentTool::class, new UpdateTaskFieldsTool($userA, new CommandRunner));
    }

    #[Test]
    public function reassign_task_tool_unassigns_via_the_command_layer(): void
    {
        [$userA, $orgA] = $this->userInOrg('A');
        $issue = $this->makeIssue($orgA, $userA);

        $result = (new ReassignTaskTool($userA, new CommandRunner))
            ->execute(['task_id' => $issue->id, 'assignee_id' => null]);

        $this->assertTrue($result['success']);
        $this->assertNull($issue->fresh()->assignee_id);
    }

    #[Test]
    public function update_task_fields_tool_changes_fields_via_the_command_layer(): void
    {
        [$userA, $orgA] = $this->userInOrg('A');
        $issue = $this->makeIssue($orgA, $userA);

        $result = (new UpdateTaskFieldsTool($userA, new CommandRunner))
            ->execute(['task_id' => $issue->id, 'name' => 'renamed', 'priority' => 100]);

        $this->assertTrue($result['success']);
        $this->assertSame('renamed', $issue->fresh()->name);
        $this->assertSame(100, (int) $issue->fresh()->priority);
    }

    #[Test]
    public function update_task_fields_tool_rejects_when_no_allowed_field_is_provided(): void
    {
        [$userA, $orgA] = $this->userInOrg('A');
        $issue = $this->makeIssue($orgA, $userA);

        // Only a disallowed field (status) is passed → nothing to update.
        $result = (new UpdateTaskFieldsTool($userA, new CommandRunner))
            ->execute(['task_id' => $issue->id, 'status' => 'done']);

        $this->assertFalse($result['success']);
    }

    // --- helpers ---

    /** @return array{0: User, 1: Organization} */
    private function userInOrg(string $suffix): array
    {
        $user = User::factory()->create();
        $org = Organization::create([
            'name' => "Org {$suffix}",
            'slug' => 'org-'.strtolower($suffix).'-'.uniqid(),
        ]);
        $org->users()->attach($user->id, ['role' => 'manager']);

        return [$user, $org];
    }

    private function makeIssue(Organization $org, User $assignee): Issue
    {
        return Issue::create([
            'user_id' => $assignee->id,
            'organization_id' => $org->id,
            'assignee_id' => $assignee->id,
            'name' => 'task',
            'type' => Issue::TYPE_ORGANIZATION,
            'status' => 'open',
        ]);
    }
}
