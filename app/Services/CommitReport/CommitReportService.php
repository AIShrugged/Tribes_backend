<?php

namespace App\Services\CommitReport;

use App\Models\CommitReport;
use App\Models\CommitReportItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CommitReportService
{
    /**
     * Idempotent, race-safe persistence of a commit report for a (repo, branch, period) window.
     *
     * Uses an atomic INSERT ... ON CONFLICT DO UPDATE (upsert) keyed on the unique window,
     * so two concurrent workers cannot create duplicates or hit a unique-violation. upsert()
     * bypasses model casting, so json columns are pre-encoded here.
     */
    public function save(array $data): CommitReport
    {
        $key = [
            'repo' => $data['repo'],
            'branch' => $data['branch'],
            'period_start' => $data['period_start'],
            'period_end' => $data['period_end'],
        ];

        // The SAME normalized item set seeds both the JSON `items` mirror (the 12 anchors read it)
        // and the commit_report_items child rows — one source, two projections.
        $items = is_array($data['items'] ?? null)
            ? $data['items']
            : ['added' => [], 'fixed' => [], 'skipped' => []];
        $added = is_array($items['added'] ?? null) ? array_values($items['added']) : [];
        $fixed = is_array($items['fixed'] ?? null) ? array_values($items['fixed']) : [];

        $now = now();
        $row = array_merge($key, [
            'summary' => $data['summary'] ?? null,
            'items' => json_encode($items, JSON_UNESCAPED_UNICODE),
            'commit_count' => (int) ($data['commit_count'] ?? 0),
            'total_in_window' => (int) ($data['total_in_window'] ?? 0),
            'commit_shas' => json_encode($data['commit_shas'] ?? [], JSON_UNESCAPED_UNICODE),
            'source' => 'github',
            'status' => $data['status'] ?? 'done',
            'generated_by_agent_task_run_id' => $data['generated_by_agent_task_run_id'] ?? null,
            'organization_id' => $data['organization_id'] ?? null,
            'team_id' => $data['team_id'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DB::transaction(function () use ($key, $row, $added, $fixed) {
            CommitReport::upsert(
                [$row],
                ['repo', 'branch', 'period_start', 'period_end'],
                ['summary', 'items', 'commit_count', 'total_in_window', 'commit_shas', 'source', 'status', 'generated_by_agent_task_run_id', 'organization_id', 'team_id', 'updated_at'],
            );

            $report = CommitReport::where($key)->firstOrFail();
            $this->syncItems($report, $added, $fixed);

            return $report;
        });
    }

    /**
     * Materialize one commit_report_items row per added/fixed item, inside save()'s transaction.
     * Writes ONLY Pass-1-owned columns; deep-review columns (architect_comment/spec_coverage/
     * review_status) are never touched here, so a Pass-1 re-run can't wipe a Pass-2 result.
     * Prune is review-safe: a row that already carries a review is never deleted.
     *
     * @param  array<int,mixed>  $added
     * @param  array<int,mixed>  $fixed
     */
    private function syncItems(CommitReport $report, array $added, array $fixed): void
    {
        $now = now();
        $rows = [];
        $seen = [];
        $position = 0;

        foreach ([['added', $added], ['fixed', $fixed]] as [$bucket, $items]) {
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $sha = trim((string) ($item['sha'] ?? ''));
                if ($sha === '' || isset($seen[$sha])) {
                    continue; // skip empty + de-dup a sha appearing in both buckets
                }
                $seen[$sha] = true;

                $matchedId = $this->intOrNull($item['matched_issue_id'] ?? null);
                $related = $item['related_issues'] ?? null;

                $rows[] = [
                    'commit_report_id' => $report->id,
                    'sha' => $sha,
                    'bucket' => $bucket,
                    'title' => isset($item['title']) ? (string) $item['title'] : null,
                    'summary' => isset($item['summary']) ? (string) $item['summary'] : null,
                    'position' => $position++,
                    'matched_issue_id' => $matchedId,
                    'matched_issue_name' => isset($item['matched_issue_name']) ? (string) $item['matched_issue_name'] : null,
                    'matched_confidence' => isset($item['matched_confidence']) ? (string) $item['matched_confidence'] : null,
                    'match_source' => isset($item['match_source']) ? (string) $item['match_source'] : null,
                    'unmatched' => $matchedId === null,
                    'evidence' => isset($item['evidence']) ? (string) $item['evidence'] : null,
                    'related_issues' => is_array($related) ? json_encode($related, JSON_UNESCAPED_UNICODE) : null,
                    // insert-only default (NOT in the update list) → preserved on a re-run
                    'review_status' => $matchedId === null ? 'not_flagged' : 'pending',
                    // a re-appearing sha clears any prior tombstone (dropped_at IS in the update list)
                    'dropped_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if (! empty($rows)) {
            CommitReportItem::upsert(
                $rows,
                ['commit_report_id', 'sha'],
                ['bucket', 'title', 'summary', 'position', 'matched_issue_id', 'matched_issue_name', 'matched_confidence', 'match_source', 'unmatched', 'evidence', 'related_issues', 'dropped_at', 'updated_at'],
            );
        }

        $currentShas = array_column($rows, 'sha');
        $protected = ['done', 'in_progress', 'failed', 'deferred'];

        // Prune: delete rows whose sha is gone from this report, EXCEPT ones carrying a Pass-2
        // result (those are preserved for audit).
        CommitReportItem::query()
            ->where('commit_report_id', $report->id)
            ->when(! empty($currentShas), fn ($q) => $q->whereNotIn('sha', $currentShas))
            ->whereNotIn('review_status', $protected)
            ->delete();

        // Tombstone the preserved-but-gone rows so every read path (detail arrays + list counts)
        // excludes them instead of leaving visible orphans. Disjoint from the prune above; a
        // re-appearing sha had its dropped_at cleared by the upsert.
        CommitReportItem::query()
            ->where('commit_report_id', $report->id)
            ->when(! empty($currentShas), fn ($q) => $q->whereNotIn('sha', $currentShas))
            ->whereIn('review_status', $protected)
            ->whereNull('dropped_at')
            ->update(['dropped_at' => $now, 'updated_at' => $now]);
    }

    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    /** Watermark: the most recent covered period_end for a repo+branch, or null when none. */
    public function latestPeriodEnd(string $repo, string $branch): ?Carbon
    {
        $value = CommitReport::query()->forRepoBranch($repo, $branch)->value('period_end');

        return $value ? Carbon::parse($value) : null;
    }

    /** Status string of the latest report for a repo+branch, or null when none. */
    public function latestStatus(string $repo, string $branch): ?string
    {
        return CommitReport::query()->forRepoBranch($repo, $branch)->value('status');
    }

    public function existsForWindow(string $repo, string $branch, string $periodStart, string $periodEnd): bool
    {
        return CommitReport::query()
            ->where('repo', $repo)
            ->where('branch', $branch)
            ->where('period_start', $periodStart)
            ->where('period_end', $periodEnd)
            ->exists();
    }

    /**
     * Race-safe per-item deep-review write-back (Pass 2). Locks the single (report, sha) row and
     * writes ONLY deep-review columns + review_status='done'. Distinct shas are distinct rows, so
     * parallel sub-runs never collide; a same-sha retry is idempotent. Returns false if the item
     * is absent or the report belongs to a different organization (tenant guard).
     *
     * @param  array{architect_comment?:?array, spec_coverage?:?string}  $review
     */
    public function applyDeepReview(int $commitReportId, string $sha, array $review, ?int $expectedOrgId = null): bool
    {
        return DB::transaction(function () use ($commitReportId, $sha, $review, $expectedOrgId) {
            $item = CommitReportItem::query()
                ->where('commit_report_id', $commitReportId)
                ->where('sha', $sha)
                ->lockForUpdate()
                ->first();

            if (! $item) {
                return false;
            }

            if ($expectedOrgId !== null) {
                $reportOrg = CommitReport::query()->whereKey($commitReportId)->value('organization_id');
                if ($reportOrg !== null && (int) $reportOrg !== $expectedOrgId) {
                    return false; // tenant guard: never write across organizations
                }
            }

            $item->forceFill([
                'architect_comment' => $review['architect_comment'] ?? null,
                'spec_coverage' => $review['spec_coverage'] ?? null,
                'review_status' => 'done',
            ])->save();

            return true;
        });
    }

    /** Mark an item's review failed — guarded so a finished review is never demoted. */
    public function markItemReviewFailed(int $commitReportId, string $sha): void
    {
        CommitReportItem::query()
            ->where('commit_report_id', $commitReportId)
            ->where('sha', $sha)
            ->whereNotIn('review_status', ['done'])
            ->update(['review_status' => 'failed', 'updated_at' => now()]);
    }
}
