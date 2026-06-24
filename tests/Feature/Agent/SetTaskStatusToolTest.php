<?php

namespace Tests\Feature\Agent;

use App\Models\AgentCommandAudit;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\User;
use App\Services\Agent\Tools\Contracts\HighImpactAgentTool;
use App\Services\Agent\Tools\SetTaskStatusTool;
use App\Services\Commands\CommandRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SetTaskStatusToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
    }

    private function tool(User $user): SetTaskStatusTool
    {
        return new SetTaskStatusTool($user, new CommandRunner);
    }

    #[Test]
    public function it_is_high_impact_so_the_taint_gate_covers_it(): void
    {
        [$userA] = $this->userInOrg('A');
        $this->assertInstanceOf(HighImpactAgentTool::class, $this->tool($userA));
    }

    #[Test]
    public function it_closes_a_task_and_writes_audit(): void
    {
        [$userA, $orgA] = $this->userInOrg('A');
        $issue = $this->makeIssue($orgA, $userA, 'task', 'open');

        $result = $this->tool($userA)->execute(['task_id' => $issue->id, 'status' => 'done']);

        $this->assertTrue($result['success']);
        $this->assertSame('done', $result['new_status']);
        $this->assertSame('done', $issue->fresh()->status);
        $this->assertSame(1, AgentCommandAudit::where('target_id', $issue->id)->count());
    }

    #[Test]
    public function it_rejects_a_task_outside_the_actors_tenant(): void
    {
        [$userA] = $this->userInOrg('A');
        [$userB, $orgB] = $this->userInOrg('B');
        $issueB = $this->makeIssue($orgB, $userB, 'foreign', 'open');

        $result = $this->tool($userA)->execute(['task_id' => $issueB->id, 'status' => 'done']);

        $this->assertFalse($result['success']);
        $this->assertSame('open', $issueB->fresh()->status);
        $this->assertSame(0, AgentCommandAudit::count());
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

    private function makeIssue(Organization $org, User $assignee, string $name, string $status = 'open'): Issue
    {
        return Issue::create([
            'user_id' => $assignee->id,
            'organization_id' => $org->id,
            'assignee_id' => $assignee->id,
            'name' => $name,
            'type' => Issue::TYPE_ORGANIZATION,
            'status' => $status,
        ]);
    }
}
