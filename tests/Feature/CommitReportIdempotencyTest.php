<?php

namespace Tests\Feature;

use App\Models\CommitReport;
use App\Models\Organization;
use App\Services\CommitReport\CommitReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CommitReportIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function service(): CommitReportService
    {
        return app(CommitReportService::class);
    }

    private function payload(array $overrides = []): array
    {
        $shaA = str_pad('a', 40, '0');
        $shaB = str_pad('b', 40, '0');

        return array_merge([
            'repo' => 'AIShrugged/Tribes_backend',
            'branch' => 'dev',
            'period_start' => '2026-06-15',
            'period_end' => '2026-06-15',
            'summary' => 'Added X, fixed Y.',
            'items' => [
                'added' => [['sha' => $shaA, 'title' => 'feat: x', 'summary' => 'added x']],
                'fixed' => [['sha' => $shaB, 'title' => 'fix: y', 'summary' => 'fixed y']],
                'skipped' => [],
            ],
            'commit_count' => 2,
            'total_in_window' => 3,
            'commit_shas' => [$shaA, $shaB],
            'status' => 'done',
        ], $overrides);
    }

    #[Test]
    public function it_saves_a_report_row(): void
    {
        $report = $this->service()->save($this->payload());

        $this->assertDatabaseCount('commit_reports', 1);
        $this->assertSame('AIShrugged/Tribes_backend', $report->repo);
        $this->assertSame('dev', $report->branch);
        $this->assertSame('2026-06-15', $report->period_start->toDateString());
        $this->assertSame('2026-06-15', $report->period_end->toDateString());
        $this->assertIsArray($report->items);
        $this->assertCount(1, $report->items['added']);
        $this->assertCount(1, $report->items['fixed']);
        $this->assertSame(2, $report->commit_count);
        $this->assertSame(3, $report->total_in_window);
    }

    #[Test]
    public function it_is_idempotent_for_the_same_window(): void
    {
        $this->service()->save($this->payload(['summary' => 'first']));
        $report = $this->service()->save($this->payload(['summary' => 'second', 'commit_count' => 5]));

        $this->assertDatabaseCount('commit_reports', 1);
        $this->assertSame('second', $report->summary);
        $this->assertSame(5, $report->commit_count);
    }

    #[Test]
    public function it_does_not_throw_when_a_row_already_exists(): void
    {
        // Simulate a concurrent winner having inserted the row first.
        CommitReport::create($this->payload());
        $this->assertDatabaseCount('commit_reports', 1);

        $report = $this->service()->save($this->payload(['summary' => 'after-race']));

        $this->assertDatabaseCount('commit_reports', 1);
        $this->assertSame('after-race', $report->summary);
    }

    #[Test]
    public function a_different_period_creates_a_second_row(): void
    {
        $this->service()->save($this->payload());
        $this->service()->save($this->payload([
            'period_start' => '2026-06-16',
            'period_end' => '2026-06-16',
        ]));

        $this->assertDatabaseCount('commit_reports', 2);
    }

    #[Test]
    public function it_reads_the_latest_period_end_watermark(): void
    {
        $this->assertNull($this->service()->latestPeriodEnd('AIShrugged/Tribes_backend', 'dev'));

        $this->service()->save($this->payload(['period_start' => '2026-06-14', 'period_end' => '2026-06-14']));
        $this->service()->save($this->payload(['period_start' => '2026-06-15', 'period_end' => '2026-06-15']));

        $watermark = $this->service()->latestPeriodEnd('AIShrugged/Tribes_backend', 'dev');
        $this->assertSame('2026-06-15', $watermark?->toDateString());
    }

    #[Test]
    public function it_persists_organization_scoping(): void
    {
        $org = Organization::create(['name' => 'Commit Org', 'slug' => 'commit-org']);

        $report = $this->service()->save($this->payload(['organization_id' => $org->id]));

        $this->assertSame($org->id, $report->organization_id);
        $this->assertDatabaseHas('commit_reports', [
            'id' => $report->id,
            'organization_id' => $org->id,
        ]);
    }
}
