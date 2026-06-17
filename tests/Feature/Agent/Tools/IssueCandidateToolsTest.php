<?php

namespace Tests\Feature\Agent\Tools;

use App\Models\Issue;
use App\Models\Organization;
use App\Models\OrganizationIssueType;
use App\Services\Agent\Support\AgentRunToolBudget;
use App\Services\Agent\Tools\GetIssueCandidatesTool;
use App\Services\Agent\Tools\GetIssueDetailTool;
use App\Services\Agent\Tools\SearchIssuesByTextTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IssueCandidateToolsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Cands', 'slug' => 'cands-org']);
        AgentRunToolBudget::reset();
    }

    private function candidates(): GetIssueCandidatesTool
    {
        return new GetIssueCandidatesTool(null, $this->org->id, null);
    }

    #[Test]
    public function candidate_window_includes_open_and_recently_closed_excludes_old_closed(): void
    {
        $open = Issue::create(['name' => 'Open task', 'organization_id' => $this->org->id, 'status' => 'open']);
        $recent = Issue::create(['name' => 'Recent done', 'organization_id' => $this->org->id, 'status' => 'done']);
        $recent->forceFill(['close_date' => now()->subDays(13)])->save();
        $old = Issue::create(['name' => 'Old done', 'organization_id' => $this->org->id, 'status' => 'done']);
        $old->forceFill(['close_date' => now()->subDays(20)])->save();

        $ids = array_column($this->candidates()->execute(['development_only' => false])['issues'], 'id');
        $this->assertContains($open->id, $ids);
        $this->assertContains($recent->id, $ids);
        $this->assertNotContains($old->id, $ids);
    }

    #[Test]
    public function development_only_excludes_an_organization_base_type_issue(): void
    {
        $dev = Issue::create(['name' => 'Dev task', 'organization_id' => $this->org->id, 'status' => 'open']);
        $orgType = OrganizationIssueType::create([
            'organization_id' => $this->org->id, 'key' => 'org-task', 'name' => 'Org', 'base_type' => 'organization', 'is_active' => true,
        ]);
        $orgIssue = Issue::create(['name' => 'Org task', 'organization_id' => $this->org->id, 'status' => 'open', 'issue_type_id' => $orgType->id]);

        $ids = array_column($this->candidates()->execute(['development_only' => true])['issues'], 'id');
        $this->assertContains($dev->id, $ids, 'development issue must be a candidate');
        $this->assertNotContains($orgIssue->id, $ids, 'organization base_type issue must be excluded');
    }

    #[Test]
    public function cross_org_issue_is_excluded(): void
    {
        $mine = Issue::create(['name' => 'mine', 'organization_id' => $this->org->id, 'status' => 'open']);
        $other = Organization::create(['name' => 'Other', 'slug' => 'cands-other']);
        $foreign = Issue::create(['name' => 'foreign', 'organization_id' => $other->id, 'status' => 'open']);

        $ids = array_column($this->candidates()->execute(['development_only' => false])['issues'], 'id');
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($foreign->id, $ids);
    }

    #[Test]
    public function search_ranks_by_fts(): void
    {
        $a = Issue::create(['name' => 'Доделать дашборд закрытых задач', 'organization_id' => $this->org->id, 'status' => 'open']);
        $res = (new SearchIssuesByTextTool(null, $this->org->id, null, 777))->execute(['query' => 'дашборд закрытых']);
        $this->assertTrue($res['success']);
        $this->assertContains($a->id, array_column($res['issues'], 'id'));
    }

    #[Test]
    public function search_falls_back_to_ilike_on_name(): void
    {
        $b = Issue::create(['name' => 'Refactor PaginationTrait', 'organization_id' => $this->org->id, 'status' => 'open']);
        $res = (new SearchIssuesByTextTool(null, $this->org->id, null, 779))->execute(['query' => 'Paginatio']);
        $this->assertSame('ilike', $res['source']);
        $this->assertContains($b->id, array_column($res['issues'], 'id'));
    }

    #[Test]
    public function search_rejects_a_blank_query(): void
    {
        $res = (new SearchIssuesByTextTool(null, $this->org->id, null, 778))->execute(['query' => '  ']);
        $this->assertFalse($res['success']);
    }

    #[Test]
    public function search_budget_exhausts_per_run(): void
    {
        config(['agent.commit_report.match.search_budget_per_run' => 3]);
        AgentRunToolBudget::reset();
        $tool = new SearchIssuesByTextTool(null, $this->org->id, null, 999);

        for ($i = 0; $i < 3; $i++) {
            $this->assertArrayNotHasKey('budget_exhausted', $tool->execute(['query' => 'x']));
        }
        $res = $tool->execute(['query' => 'x']);
        $this->assertTrue($res['budget_exhausted'] ?? false);
    }

    #[Test]
    public function get_issue_detail_returns_full_description_and_caps_long_ones(): void
    {
        $short = Issue::create(['name' => 'short', 'organization_id' => $this->org->id, 'status' => 'open', 'description' => str_repeat('s', 500)]);
        $res = (new GetIssueDetailTool(null, $this->org->id, null))->execute(['issue_id' => $short->id]);
        $this->assertTrue($res['success']);
        $this->assertSame(500, mb_strlen($res['description'])); // FULL (> the 300 cap of other tools)
        $this->assertFalse($res['description_truncated']);
        $this->assertTrue($res['has_description']);

        $long = Issue::create(['name' => 'long', 'organization_id' => $this->org->id, 'status' => 'open', 'description' => str_repeat('x', 13000)]);
        $res2 = (new GetIssueDetailTool(null, $this->org->id, null))->execute(['issue_id' => $long->id]);
        $this->assertTrue($res2['description_truncated']);
        $this->assertLessThan(13000, mb_strlen($res2['description']));
        $this->assertLessThan(15000, strlen(json_encode($res2))); // envelope stays under the tool-result cap
    }

    #[Test]
    public function get_issue_detail_cross_org_and_missing_share_an_identical_message(): void
    {
        $other = Organization::create(['name' => 'Other2', 'slug' => 'cands-other2']);
        $foreign = Issue::create(['name' => 'foreign', 'organization_id' => $other->id, 'status' => 'open']);

        $crossOrg = (new GetIssueDetailTool(null, $this->org->id, null))->execute(['issue_id' => $foreign->id]);
        $missing = (new GetIssueDetailTool(null, $this->org->id, null))->execute(['issue_id' => 999999]);

        $this->assertFalse($crossOrg['success']);
        $this->assertFalse($missing['success']);
        $this->assertSame($crossOrg['error'], $missing['error']); // no probing
    }
}
