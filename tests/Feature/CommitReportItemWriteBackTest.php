<?php

namespace Tests\Feature;

use App\Models\CommitReport;
use App\Models\CommitReportItem;
use App\Models\Organization;
use App\Services\Agent\Tools\UpdateCommitReportItemTool;
use App\Services\CommitReport\CommitReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CommitReportItemWriteBackTest extends TestCase
{
    use RefreshDatabase;

    private function sha(string $s): string
    {
        return str_pad($s, 40, '0');
    }

    private function seedReport(Organization $org, array $shas): CommitReport
    {
        $added = array_map(fn ($s) => ['sha' => $s, 'title' => 't', 'summary' => 's'], $shas);

        return app(CommitReportService::class)->save([
            'repo' => 'AIShrugged/Tribes_backend', 'branch' => 'dev',
            'period_start' => '2026-06-15', 'period_end' => '2026-06-15', 'summary' => 'x',
            'items' => ['added' => $added, 'fixed' => [], 'skipped' => []],
            'organization_id' => $org->id,
        ]);
    }

    private function tool(?int $orgId): UpdateCommitReportItemTool
    {
        return new UpdateCommitReportItemTool(app(CommitReportService::class), $orgId);
    }

    private function review(int $reportId, string $sha, string $coverage = 'covered'): array
    {
        return [
            'commit_report_id' => $reportId, 'sha' => $sha,
            'architect_comment' => ['good' => 'g', 'bad' => 'b', 'improve' => 'i', 'covers_spec' => 'yes'],
            'spec_coverage' => $coverage,
        ];
    }

    #[Test]
    public function write_back_is_idempotent(): void
    {
        $org = Organization::create(['name' => 'O', 'slug' => 'wb-1']);
        $report = $this->seedReport($org, [$this->sha('a')]);

        $this->tool($org->id)->execute($this->review($report->id, $this->sha('a'), 'partial'));
        $r2 = $this->tool($org->id)->execute($this->review($report->id, $this->sha('a'), 'covered'));

        $this->assertTrue($r2['was_found']);
        $this->assertSame(1, CommitReportItem::where('commit_report_id', $report->id)->count());
        $item = CommitReportItem::where('commit_report_id', $report->id)->first();
        $this->assertSame('covered', $item->spec_coverage);
        $this->assertSame('done', $item->review_status);
        $this->assertSame('g', $item->architect_comment['good']);
    }

    #[Test]
    public function two_distinct_shas_both_persist_without_clobbering(): void
    {
        $org = Organization::create(['name' => 'O', 'slug' => 'wb-2']);
        $report = $this->seedReport($org, [$this->sha('a'), $this->sha('b')]);

        $this->tool($org->id)->execute($this->review($report->id, $this->sha('a'), 'covered'));
        $this->tool($org->id)->execute($this->review($report->id, $this->sha('b'), 'uncovered'));

        $a = CommitReportItem::where('commit_report_id', $report->id)->where('sha', $this->sha('a'))->first();
        $b = CommitReportItem::where('commit_report_id', $report->id)->where('sha', $this->sha('b'))->first();
        $this->assertSame('covered', $a->spec_coverage);
        $this->assertSame('done', $a->review_status);
        $this->assertSame('uncovered', $b->spec_coverage);
        $this->assertSame('done', $b->review_status);
    }

    #[Test]
    public function write_back_to_a_non_existent_sha_changes_nothing(): void
    {
        $org = Organization::create(['name' => 'O', 'slug' => 'wb-3']);
        $report = $this->seedReport($org, [$this->sha('a')]);

        $res = $this->tool($org->id)->execute($this->review($report->id, $this->sha('z')));

        $this->assertFalse($res['was_found']);
        $item = CommitReportItem::where('commit_report_id', $report->id)->first();
        $this->assertNull($item->spec_coverage);
        $this->assertNotSame('done', $item->review_status);
    }

    #[Test]
    public function cross_org_write_back_is_rejected(): void
    {
        $orgA = Organization::create(['name' => 'A', 'slug' => 'wb-4a']);
        $report = $this->seedReport($orgA, [$this->sha('a')]);
        $orgB = Organization::create(['name' => 'B', 'slug' => 'wb-4b']);

        $res = $this->tool($orgB->id)->execute($this->review($report->id, $this->sha('a')));

        $this->assertFalse($res['was_found']);
        $item = CommitReportItem::where('commit_report_id', $report->id)->first();
        $this->assertNull($item->spec_coverage); // untouched
    }

    #[Test]
    public function failed_does_not_demote_a_done_item(): void
    {
        $org = Organization::create(['name' => 'O', 'slug' => 'wb-5']);
        $report = $this->seedReport($org, [$this->sha('a')]);
        $this->tool($org->id)->execute($this->review($report->id, $this->sha('a')));

        app(CommitReportService::class)->markItemReviewFailed($report->id, $this->sha('a'));

        $item = CommitReportItem::where('commit_report_id', $report->id)->first();
        $this->assertSame('done', $item->review_status); // never demoted
    }
}
