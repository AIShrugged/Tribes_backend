<?php

namespace Tests\Feature;

use App\Models\CommitReportItem;
use App\Services\CommitReport\CommitReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CommitReportV1BackwardCompatTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): CommitReportService
    {
        return app(CommitReportService::class);
    }

    #[Test]
    public function v1_payload_saves_and_materializes_child_rows(): void
    {
        $shaA = str_pad('a', 40, '0');
        $shaB = str_pad('b', 40, '0');

        $report = $this->svc()->save([
            'repo' => 'AIShrugged/Tribes_backend',
            'branch' => 'dev',
            'period_start' => '2026-06-15',
            'period_end' => '2026-06-15',
            'summary' => 'x',
            'items' => [
                'added' => [['sha' => $shaA, 'title' => 'feat: a', 'summary' => 'added a']],
                'fixed' => [['sha' => $shaB, 'title' => 'fix: b', 'summary' => 'fixed b']],
                'skipped' => [],
            ],
            'commit_count' => 2,
            'total_in_window' => 2,
            'commit_shas' => [$shaA, $shaB],
            'status' => 'done',
        ]);

        // JSON mirror still populated (the 12 anchors depend on it).
        $this->assertCount(1, $report->items['added']);
        $this->assertCount(1, $report->items['fixed']);

        // Child rows materialized with v1 defaults.
        $this->assertSame(2, CommitReportItem::where('commit_report_id', $report->id)->count());
        $a = CommitReportItem::where('commit_report_id', $report->id)->where('sha', $shaA)->first();
        $this->assertSame('added', $a->bucket);
        $this->assertTrue($a->unmatched);
        $this->assertFalse($a->matched);
        $this->assertNull($a->matched_issue_id);
        $this->assertNull($a->spec_coverage);
        $this->assertSame('not_flagged', $a->review_status);
    }

    #[Test]
    public function rerun_preserves_a_finished_review_and_does_not_prune_a_dropped_reviewed_sha(): void
    {
        $shaA = str_pad('a', 40, '0');
        $base = [
            'repo' => 'AIShrugged/Tribes_backend', 'branch' => 'dev',
            'period_start' => '2026-06-15', 'period_end' => '2026-06-15', 'summary' => 'x',
            'items' => ['added' => [['sha' => $shaA, 'title' => 'feat: a', 'summary' => 'a']], 'fixed' => [], 'skipped' => []],
        ];
        $report = $this->svc()->save($base);

        // Simulate Pass-2 having reviewed this item.
        CommitReportItem::where('commit_report_id', $report->id)->where('sha', $shaA)->update([
            'review_status' => 'done',
            'spec_coverage' => 'covered',
            'architect_comment' => json_encode(['good' => 'g']),
        ]);

        // Re-run that DROPS the reviewed sha from added/fixed (now only skipped).
        $this->svc()->save(array_merge($base, [
            'items' => ['added' => [], 'fixed' => [], 'skipped' => [['sha' => $shaA]]],
        ]));

        $row = CommitReportItem::where('commit_report_id', $report->id)->where('sha', $shaA)->first();
        $this->assertNotNull($row, 'a reviewed row must never be pruned');
        $this->assertSame('done', $row->review_status);
        $this->assertSame('covered', $row->spec_coverage);
    }
}
