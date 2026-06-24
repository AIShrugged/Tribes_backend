<?php

namespace Tests\Feature\Agent;

use App\Models\AgentCommandAudit;
use App\Models\Issue;
use App\Models\IssueStatusHistory;
use App\Models\Organization;
use App\Models\User;
use App\Services\Commands\CommandAuthorizationException;
use App\Services\Commands\CommandRunner;
use App\Services\Commands\Issue\ReassignIssueCommand;
use App\Services\Commands\Issue\SetIssueStatusCommand;
use App\Services\Commands\Issue\UpdateIssueFieldsCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CommandRunnerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
    }

    private function runner(): CommandRunner
    {
        return new CommandRunner;
    }

    #[Test]
    public function it_closes_a_task_through_the_domain_layer_and_writes_audit(): void
    {
        [$userA, $orgA] = $this->userInOrg('A');
        $issue = $this->makeIssue($orgA, $userA, 'task', 'open');

        $result = $this->runner()->run(new SetIssueStatusCommand($issue->id, 'done'), $userA);

        $this->assertSame('done', $issue->fresh()->status);
        $this->assertSame('set_issue_status', $result->name);

        // Observer fired (domain side-effect), not bypassed.
        $this->assertTrue(
            IssueStatusHistory::where('issue_id', $issue->id)->where('to_status', 'done')->exists(),
            'IssueObserver should have recorded the status transition'
        );

        // Audit row with reversible payload.
        $audit = AgentCommandAudit::where('target_type', 'issue')->where('target_id', $issue->id)->first();
        $this->assertNotNull($audit);
        $this->assertSame('set_issue_status', $audit->command_type);
        $this->assertSame($userA->id, $audit->actor_id);
        $this->assertSame('open', $audit->inverse_payload['status']);
    }

    #[Test]
    public function it_rejects_a_task_outside_the_actors_tenant_without_writing_audit(): void
    {
        [$userA] = $this->userInOrg('A');
        [$userB, $orgB] = $this->userInOrg('B');
        $issueB = $this->makeIssue($orgB, $userB, 'foreign', 'open');

        try {
            $this->runner()->run(new SetIssueStatusCommand($issueB->id, 'done'), $userA);
            $this->fail('Expected CommandAuthorizationException for a foreign-tenant task');
        } catch (CommandAuthorizationException) {
            // expected
        }

        $this->assertSame('open', $issueB->fresh()->status, 'Foreign task must not be mutated');
        $this->assertSame(0, AgentCommandAudit::count(), 'No audit row for an unauthorized command');
    }

    #[Test]
    public function it_rejects_an_invalid_status(): void
    {
        [$userA, $orgA] = $this->userInOrg('A');
        $issue = $this->makeIssue($orgA, $userA, 'task', 'open');

        $this->expectException(CommandAuthorizationException::class);
        $this->runner()->run(new SetIssueStatusCommand($issue->id, 'totally_bogus'), $userA);
    }

    #[Test]
    public function the_inverse_payload_restores_the_previous_status(): void
    {
        [$userA, $orgA] = $this->userInOrg('A');
        $issue = $this->makeIssue($orgA, $userA, 'task', 'open');

        $result = $this->runner()->run(new SetIssueStatusCommand($issue->id, 'done'), $userA);
        $this->assertSame('done', $issue->fresh()->status);

        $inverse = $result->inversePayload;
        $this->assertSame('open', $inverse['status']);

        // Applying the inverse reverses the change.
        $this->runner()->run(new SetIssueStatusCommand($inverse['issue_id'], $inverse['status']), $userA);
        $this->assertSame('open', $issue->fresh()->status);
    }

    #[Test]
    public function it_rejects_reopen_as_out_of_scope_for_v1(): void
    {
        [$userA, $orgA] = $this->userInOrg('A');
        $issue = $this->makeIssue($orgA, $userA, 'task', 'done');

        $this->expectException(CommandAuthorizationException::class);
        $this->runner()->run(new SetIssueStatusCommand($issue->id, 'reopen'), $userA);
    }

    #[Test]
    public function it_reassigns_a_task_and_the_inverse_restores_the_previous_assignee(): void
    {
        [$userA, $orgA] = $this->userInOrg('A');
        $issue = $this->makeIssue($orgA, $userA, 'task', 'open'); // assignee = userA

        $result = $this->runner()->run(new ReassignIssueCommand($issue->id, null), $userA); // unassign
        $this->assertNull($issue->fresh()->assignee_id);
        $this->assertSame($userA->id, $result->inversePayload['assignee_id']);

        $inv = $result->inversePayload;
        $this->runner()->run(new ReassignIssueCommand($inv['issue_id'], $inv['assignee_id']), $userA);
        $this->assertSame($userA->id, $issue->fresh()->assignee_id);
    }

    #[Test]
    public function it_rejects_reassigning_to_a_user_outside_the_actors_organizations(): void
    {
        [$userA, $orgA] = $this->userInOrg('A');
        $issue = $this->makeIssue($orgA, $userA, 'task', 'open');
        $foreign = User::factory()->create(); // not in any of userA's orgs

        $this->expectException(CommandAuthorizationException::class);
        $this->runner()->run(new ReassignIssueCommand($issue->id, $foreign->id), $userA);
    }

    #[Test]
    public function it_rejects_reassigning_to_a_user_in_another_actor_org_but_not_the_issues_org(): void
    {
        [$userA, $orgA] = $this->userInOrg('A');

        // actorA also belongs to a second org; the candidate is in THAT org, not the issue's org.
        $orgC = Organization::create(['name' => 'Org C', 'slug' => 'org-c-'.uniqid()]);
        $orgC->users()->attach($userA->id, ['role' => 'employee']);
        $candidate = User::factory()->create();
        $orgC->users()->attach($candidate->id, ['role' => 'employee']);

        $issue = $this->makeIssue($orgA, $userA, 'task', 'open'); // issue lives in orgA

        // Old behaviour (shares-an-org-with-actor) would ALLOW this; the fix scopes to the issue's org.
        $this->expectException(CommandAuthorizationException::class);
        $this->runner()->run(new ReassignIssueCommand($issue->id, $candidate->id), $userA);
    }

    #[Test]
    public function it_allows_reassigning_to_a_user_in_the_issues_organization(): void
    {
        [$userA, $orgA] = $this->userInOrg('A');
        $teammate = User::factory()->create();
        $orgA->users()->attach($teammate->id, ['role' => 'employee']);

        $issue = $this->makeIssue($orgA, $userA, 'task', 'open');

        $this->runner()->run(new ReassignIssueCommand($issue->id, $teammate->id), $userA);

        $this->assertSame($teammate->id, $issue->fresh()->assignee_id);
    }

    #[Test]
    public function it_updates_fields_and_the_inverse_restores_them(): void
    {
        [$userA, $orgA] = $this->userInOrg('A');
        $issue = $this->makeIssue($orgA, $userA, 'original', 'open');

        $result = $this->runner()->run(
            new UpdateIssueFieldsCommand($issue->id, ['name' => 'renamed', 'priority' => 100]),
            $userA
        );
        $this->assertSame('renamed', $issue->fresh()->name);
        $this->assertSame(100, (int) $issue->fresh()->priority);

        $this->runner()->run(
            new UpdateIssueFieldsCommand($issue->id, $result->inversePayload['fields']),
            $userA
        );
        $this->assertSame('original', $issue->fresh()->name);
    }

    #[Test]
    public function update_fields_rejects_when_no_allowed_field_is_provided(): void
    {
        [$userA, $orgA] = $this->userInOrg('A');
        $issue = $this->makeIssue($orgA, $userA, 'task', 'open');

        // status is NOT an allowed field here (use set_task_status) → nothing to update.
        $this->expectException(CommandAuthorizationException::class);
        $this->runner()->run(new UpdateIssueFieldsCommand($issue->id, ['status' => 'done']), $userA);
    }

    #[Test]
    public function closing_then_inverting_restores_the_derived_close_date(): void
    {
        [$userA, $orgA] = $this->userInOrg('A');
        $issue = $this->makeIssue($orgA, $userA, 'task', 'open');
        $this->assertNull($issue->close_date);

        $result = $this->runner()->run(new SetIssueStatusCommand($issue->id, 'done'), $userA);
        $this->assertNotNull($issue->fresh()->close_date, 'close_date is set by the model hook on close');

        // Inverse restores the status; the booted() hook recomputes close_date back to null.
        $inv = $result->inversePayload;
        $this->runner()->run(new SetIssueStatusCommand($inv['issue_id'], $inv['status']), $userA);
        $this->assertNull($issue->fresh()->close_date, 'reverting status must clear the derived close_date');
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
