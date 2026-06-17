<?php

namespace Tests\Feature;

use App\Models\CommitReportItem;
use App\Models\Issue;
use App\Models\Organization;
use App\Services\Agent\Tools\SaveCommitReportTool;
use App\Services\CommitReport\CommitReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CommitReportMatcherPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private function sha(string $s): string
    {
        return str_pad($s, 40, '0');
    }

    private function tool(Organization $org): SaveCommitReportTool
    {
        return new SaveCommitReportTool(app(CommitReportService::class), $org->id);
    }

    private function payload(array $added): array
    {
        return [
            'repo' => 'AIShrugged/Tribes_backend', 'branch' => 'dev',
            'period_start' => '2026-06-15', 'period_end' => '2026-06-15', 'summary' => 'x',
            'items' => ['added' => $added, 'fixed' => [], 'skipped' => []],
        ];
    }

    #[Test]
    public function explicit_match_to_a_real_org_issue_persists_with_backfilled_name(): void
    {
        $org = Organization::create(['name' => 'O', 'slug' => 'o-m1']);
        $issue = Issue::create(['name' => 'Доделать дашборд', 'organization_id' => $org->id, 'status' => 'open', 'description' => 'тз']);

        $res = $this->tool($org)->execute($this->payload([
            ['sha' => $this->sha('a'), 'title' => 'feat: dashboard', 'summary' => 's', 'matched_issue_id' => $issue->id, 'match_source' => 'explicit', 'matched_confidence' => 'high'],
        ]));

        $this->assertTrue($res['success']);
        $this->assertSame(1, $res['matched_count']);
        $item = CommitReportItem::where('sha', $this->sha('a'))->first();
        $this->assertFalse($item->unmatched);
        $this->assertTrue($item->matched);
        $this->assertSame($issue->id, $item->matched_issue_id);
        $this->assertSame('Доделать дашборд', $item->matched_issue_name);
        $this->assertSame('explicit', $item->match_source);
        $this->assertSame('pending', $item->review_status);
    }

    #[Test]
    public function item_without_a_matched_id_is_unmatched_and_not_flagged(): void
    {
        $org = Organization::create(['name' => 'O', 'slug' => 'o-m2']);
        $this->tool($org)->execute($this->payload([
            ['sha' => $this->sha('b'), 'title' => 'feat: x', 'summary' => 's'],
        ]));
        $item = CommitReportItem::where('sha', $this->sha('b'))->first();
        $this->assertTrue($item->unmatched);
        $this->assertNull($item->matched_issue_id);
        $this->assertSame('not_flagged', $item->review_status);
    }

    #[Test]
    public function cross_org_or_soft_deleted_matched_id_is_rejected_to_unmatched(): void
    {
        $org = Organization::create(['name' => 'O', 'slug' => 'o-m3']);
        $other = Organization::create(['name' => 'X', 'slug' => 'o-m3x']);
        $foreign = Issue::create(['name' => 'foreign', 'organization_id' => $other->id, 'status' => 'open']);
        $deleted = Issue::create(['name' => 'gone', 'organization_id' => $org->id, 'status' => 'open']);
        $deleted->delete();

        $res = $this->tool($org)->execute($this->payload([
            ['sha' => $this->sha('c'), 'title' => 'feat: a', 'summary' => 's', 'matched_issue_id' => $foreign->id, 'match_source' => 'semantic'],
            ['sha' => $this->sha('d'), 'title' => 'feat: b', 'summary' => 's', 'matched_issue_id' => $deleted->id, 'match_source' => 'explicit'],
        ]));

        $this->assertSame(0, $res['matched_count']);
        foreach ([$this->sha('c'), $this->sha('d')] as $s) {
            $item = CommitReportItem::where('sha', $s)->first();
            $this->assertTrue($item->unmatched, "$s must be unmatched");
            $this->assertNull($item->matched_issue_id);
            $this->assertSame('not_flagged', $item->review_status);
        }
    }

    #[Test]
    public function related_issues_drops_entries_without_a_numeric_id(): void
    {
        $org = Organization::create(['name' => 'O', 'slug' => 'o-m4']);
        $real = Issue::create(['name' => 'real', 'organization_id' => $org->id, 'status' => 'open']);

        $this->tool($org)->execute($this->payload([
            ['sha' => $this->sha('e'), 'title' => 'feat', 'summary' => 's', 'related_issues' => [
                ['id' => $real->id, 'name' => 'real'], ['name' => 'no id'], ['id' => 'abc'],
            ]],
        ]));

        $item = CommitReportItem::where('sha', $this->sha('e'))->first();
        $this->assertCount(1, $item->related_issues);
        $this->assertSame($real->id, $item->related_issues[0]['id']);
    }
}
