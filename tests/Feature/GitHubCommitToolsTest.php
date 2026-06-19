<?php

namespace Tests\Feature;

use App\Services\Agent\Tools\GetLastCommitReportTool;
use App\Services\Agent\Tools\GitHubGetCommitTool;
use App\Services\Agent\Tools\GitHubListCommitsTool;
use App\Services\Agent\Tools\SaveCommitReportTool;
use App\Services\CommitReport\CommitReportService;
use App\Services\GitHub\GitHubApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GitHubCommitToolsTest extends TestCase
{
    use RefreshDatabase;

    private function sha(string $seed): string
    {
        return str_pad($seed, 40, '0');
    }

    private function fakeGitHub(array $listResponse, array $commitResponse = []): void
    {
        Http::fake(function (Request $request) use ($listResponse, $commitResponse) {
            $url = $request->url();

            if (str_contains($url, '/commits/')) {
                return Http::response($commitResponse, 200);
            }
            if (str_contains($url, '/commits')) {
                return Http::response($listResponse, 200);
            }
            if (str_contains($url, '/app/installations/')) {
                return Http::response(['token' => 'fake', 'expires_at' => now()->addHour()->toIso8601String()], 201);
            }

            return Http::response([], 200);
        });
    }

    #[Test]
    public function list_commits_returns_metadata_and_deterministic_hints(): void
    {
        $this->fakeGitHub([
            ['sha' => $this->sha('a'), 'commit' => ['message' => "feat: add endpoint\n\nbody", 'author' => ['name' => 'Alice', 'date' => '2026-06-15T08:00:00Z']], 'author' => ['login' => 'alice'], 'parents' => [['sha' => 'p1']]],
            ['sha' => $this->sha('b'), 'commit' => ['message' => 'fix: correct guard', 'author' => ['name' => 'Bob', 'date' => '2026-06-15T09:00:00Z']], 'author' => ['login' => 'bob'], 'parents' => [['sha' => 'p2']]],
            ['sha' => $this->sha('c'), 'commit' => ['message' => 'chore: bump deps', 'author' => ['name' => 'Carol', 'date' => '2026-06-15T10:00:00Z']], 'author' => ['login' => 'carol'], 'parents' => [['sha' => 'p3']]],
            ['sha' => $this->sha('d'), 'commit' => ['message' => 'Merge pull request #1', 'author' => ['name' => 'Dan', 'date' => '2026-06-15T11:00:00Z']], 'author' => ['login' => 'dan'], 'parents' => [['sha' => 'p4'], ['sha' => 'p5']]],
            ['sha' => $this->sha('e'), 'commit' => ['message' => 'random change', 'author' => ['name' => 'Bot', 'date' => '2026-06-15T12:00:00Z']], 'author' => ['login' => 'dependabot[bot]'], 'parents' => [['sha' => 'p6']]],
            ['sha' => $this->sha('f'), 'commit' => ['message' => 'refactor: extract service', 'author' => ['name' => 'Frank', 'date' => '2026-06-15T13:00:00Z']], 'author' => ['login' => 'frank'], 'parents' => [['sha' => 'p7']]],
        ]);

        $tool = new GitHubListCommitsTool(app(GitHubApiClient::class));
        $result = $tool->execute(['owner' => 'o', 'repo' => 'r', 'branch' => 'dev', 'per_page' => 50]);

        $this->assertTrue($result['success']);
        $this->assertSame(6, $result['count']);
        $this->assertFalse($result['has_more']);

        foreach ($result['commits'] as $row) {
            $this->assertArrayNotHasKey('patch', $row);
            $this->assertArrayNotHasKey('files', $row);
            $this->assertSame(7, strlen($row['short_sha']));
        }

        $byHint = collect($result['commits'])->keyBy('sha');
        $this->assertSame('added', $byHint[$this->sha('a')]['prefix_hint']);
        $this->assertSame('fixed', $byHint[$this->sha('b')]['prefix_hint']);
        $this->assertSame('skip', $byHint[$this->sha('c')]['prefix_hint']);
        $this->assertSame('ambiguous', $byHint[$this->sha('f')]['prefix_hint']); // refactor is now evaluated, not skipped
        $this->assertTrue($byHint[$this->sha('d')]['is_merge']);
        $this->assertTrue($byHint[$this->sha('e')]['is_bot']);
        $this->assertFalse($byHint[$this->sha('a')]['is_merge']);
    }

    #[Test]
    public function get_commit_omits_patches_by_default_and_caps_them_when_requested(): void
    {
        $bigPatch = str_repeat('x', 9000);
        $this->fakeGitHub([], [
            'sha' => $this->sha('a'),
            'commit' => ['message' => 'feat: x', 'author' => ['name' => 'Alice', 'date' => '2026-06-15T08:00:00Z']],
            'author' => ['login' => 'alice'],
            'stats' => ['additions' => 9000, 'deletions' => 1, 'total' => 9001],
            'files' => [
                ['filename' => 'app/X.php', 'status' => 'added', 'additions' => 9000, 'deletions' => 1, 'changes' => 9001, 'patch' => $bigPatch],
            ],
        ]);

        $tool = new GitHubGetCommitTool(app(GitHubApiClient::class));

        $statsOnly = $tool->execute(['owner' => 'o', 'repo' => 'r', 'ref' => $this->sha('a')]);
        $this->assertTrue($statsOnly['success']);
        $this->assertSame(['additions' => 9000, 'deletions' => 1, 'total' => 9001], $statsOnly['stats']);
        $this->assertArrayNotHasKey('patch', $statsOnly['files'][0]);

        $withPatch = $tool->execute(['owner' => 'o', 'repo' => 'r', 'ref' => $this->sha('a'), 'include_patches' => true]);
        $this->assertArrayHasKey('patch', $withPatch['files'][0]);
        $this->assertTrue($withPatch['files'][0]['patch_truncated']);
        $this->assertLessThan(9000, strlen($withPatch['files'][0]['patch']));
    }

    #[Test]
    public function get_commit_requires_a_ref(): void
    {
        $tool = new GitHubGetCommitTool(app(GitHubApiClient::class));
        $result = $tool->execute(['owner' => 'o', 'repo' => 'r']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ref', $result['error']);
    }

    #[Test]
    public function save_and_read_watermark_round_trip(): void
    {
        $save = new SaveCommitReportTool(app(CommitReportService::class));
        $payload = [
            'repo' => 'AIShrugged/Tribes_backend',
            'branch' => 'dev',
            'period_start' => '2026-06-15',
            'period_end' => '2026-06-15',
            'summary' => 'Added X.',
            'items' => ['added' => [['sha' => $this->sha('a'), 'title' => 'feat: x', 'summary' => 'x']], 'fixed' => [], 'skipped' => []],
            'commit_count' => 1,
            'total_in_window' => 1,
            'commit_shas' => [$this->sha('a')],
            'status' => 'done',
        ];

        $first = $save->execute($payload);
        $this->assertTrue($first['success']);
        $this->assertFalse($first['was_updated']);
        $this->assertSame('2026-06-15', $first['period_end']);

        $second = $save->execute(array_merge($payload, ['summary' => 'updated']));
        $this->assertTrue($second['was_updated']);
        $this->assertDatabaseCount('commit_reports', 1);

        $last = new GetLastCommitReportTool(app(CommitReportService::class));
        $watermark = $last->execute(['repo' => 'AIShrugged/Tribes_backend', 'branch' => 'dev']);
        $this->assertTrue($watermark['success']);
        $this->assertSame('2026-06-15', $watermark['last_period_end']);
        $this->assertSame('done', $watermark['last_status']);

        $none = $last->execute(['repo' => 'AIShrugged/Other', 'branch' => 'dev']);
        $this->assertNull($none['last_period_end']);
    }

    #[Test]
    public function get_last_commit_report_computes_the_scan_window(): void
    {
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-06-16 10:00:00', 'Europe/Moscow'));
        try {
            $tool = new GetLastCommitReportTool(app(CommitReportService::class));

            // No prior report -> yesterday-only window.
            $fresh = $tool->execute(['repo' => 'AIShrugged/Tribes_backend', 'branch' => 'dev']);
            $this->assertNull($fresh['last_period_end']);
            $this->assertSame('2026-06-15', $fresh['window']['period_start']);
            $this->assertSame('2026-06-15', $fresh['window']['period_end']);
            $this->assertSame('2026-06-14T21:00:00Z', $fresh['window']['since']);
            $this->assertSame('2026-06-15T21:00:00Z', $fresh['window']['until']);

            // Prior report ending 2026-06-13 -> window resumes 06-14..06-15.
            (new SaveCommitReportTool(app(CommitReportService::class)))->execute([
                'repo' => 'AIShrugged/Tribes_backend', 'branch' => 'dev',
                'period_start' => '2026-06-13', 'period_end' => '2026-06-13',
                'summary' => 'x', 'items' => ['added' => [], 'fixed' => [], 'skipped' => []],
            ]);
            $resume = $tool->execute(['repo' => 'AIShrugged/Tribes_backend', 'branch' => 'dev']);
            $this->assertSame('2026-06-13', $resume['last_period_end']);
            $this->assertSame('2026-06-14', $resume['window']['period_start']);
            $this->assertSame('2026-06-15', $resume['window']['period_end']);
            $this->assertSame('2026-06-13T21:00:00Z', $resume['window']['since']);
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }

    #[Test]
    public function save_rebuckets_added_items_without_a_sha(): void
    {
        $save = new SaveCommitReportTool(app(CommitReportService::class));
        $save->execute([
            'repo' => 'AIShrugged/Tribes_backend', 'branch' => 'dev',
            'period_start' => '2026-06-15', 'period_end' => '2026-06-15',
            'summary' => 'x',
            'items' => [
                'added' => [
                    ['sha' => $this->sha('a'), 'title' => 'feat: ok', 'summary' => 'ok'],
                    ['title' => 'feat: no sha'], // missing sha -> re-bucketed to skipped
                ],
                'fixed' => [],
                'skipped' => [],
            ],
        ]);

        $report = \App\Models\CommitReport::first();
        $this->assertCount(1, $report->items['added']);
        $this->assertSame('no_sha', collect($report->items['skipped'])->firstWhere('title', 'feat: no sha')['reason']);
    }
}
