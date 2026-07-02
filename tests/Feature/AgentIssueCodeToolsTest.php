<?php

namespace Tests\Feature;

use App\Models\Issue;
use App\Models\Organization;
use App\Models\User;
use App\Services\Agent\Support\AgentRunToolBudget;
use App\Services\Agent\Tools\GetIssueByCodeTool;
use App\Services\Agent\Tools\GetIssueDetailTool;
use App\Services\Agent\Tools\GetOpenIssuesTool;
use App\Services\Agent\Tools\SearchIssuesByTextTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AgentIssueCodeToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        AgentRunToolBudget::reset();
    }

    private function memberWithOrganization(): array
    {
        $user = User::factory()->create();
        $organization = Organization::create(['name' => 'Dev_coding', 'slug' => 'dev-coding']);
        $organization->users()->attach($user->id, ['role' => 'manager']);

        return [$user, $organization];
    }

    #[Test]
    public function get_issue_by_code_resolves_a_scoped_issue(): void
    {
        [$user, $org] = $this->memberWithOrganization();
        $issue = Issue::create(['organization_id' => $org->id, 'name' => 'Fix login', 'type' => 'development']);

        $result = (new GetIssueByCodeTool($user, $org->id, null))->execute(['code' => 'dev-1']);

        $this->assertTrue($result['success']);
        $this->assertSame($issue->id, $result['id']);
        $this->assertSame('DEV-1', $result['code']);
        $this->assertSame(1, $result['number']);
    }

    #[Test]
    public function get_issue_by_code_returns_not_found_for_unknown_code(): void
    {
        [$user, $org] = $this->memberWithOrganization();

        $result = (new GetIssueByCodeTool($user, $org->id, null))->execute(['code' => 'DEV-999']);

        $this->assertFalse($result['success']);
    }

    #[Test]
    public function get_issue_by_code_hides_issues_outside_the_scope(): void
    {
        [$user, $org] = $this->memberWithOrganization();

        $foreignOrg = Organization::create(['name' => 'Auchan', 'slug' => 'auchan']);
        Issue::create(['organization_id' => $foreignOrg->id, 'name' => 'Hidden', 'type' => 'organization']); // AUC-1

        $result = (new GetIssueByCodeTool($user, $org->id, null))->execute(['code' => 'AUC-1']);

        $this->assertFalse($result['success']);
    }

    #[Test]
    public function get_issue_detail_exposes_the_code_and_number(): void
    {
        [$user, $org] = $this->memberWithOrganization();
        $issue = Issue::create(['organization_id' => $org->id, 'name' => 'Fix login', 'type' => 'development']);

        $result = (new GetIssueDetailTool($user, $org->id, null))->execute(['issue_id' => $issue->id]);

        $this->assertSame('DEV-1', $result['code']);
        $this->assertSame(1, $result['number']);
    }

    #[Test]
    public function get_open_issues_includes_the_code_in_each_row(): void
    {
        [$user, $org] = $this->memberWithOrganization();
        Issue::create(['organization_id' => $org->id, 'name' => 'Fix login', 'type' => 'development']);

        $result = (new GetOpenIssuesTool($user, $org->id, null))->execute([]);

        $this->assertTrue($result['success']);
        $this->assertSame('DEV-1', $result['issues'][0]['code']);
        $this->assertSame(1, $result['issues'][0]['number']);
    }

    #[Test]
    public function search_by_text_resolves_a_code_mentioned_in_the_query(): void
    {
        [$user, $org] = $this->memberWithOrganization();
        Issue::create(['organization_id' => $org->id, 'name' => 'Fix login', 'type' => 'development']); // DEV-1

        $result = (new SearchIssuesByTextTool($user, $org->id, null, null))
            ->execute(['query' => 'branch feature/DEV-1-login done']);

        $this->assertSame('code', $result['source']);
        $this->assertSame('DEV-1', $result['issues'][0]['code']);
    }
}
