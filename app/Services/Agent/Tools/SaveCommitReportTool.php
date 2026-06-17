<?php

namespace App\Services\Agent\Tools;

use App\Models\Issue;
use App\Services\CommitReport\CommitReportService;
use Illuminate\Support\Carbon;

class SaveCommitReportTool extends AbstractAgentTool
{
    public function __construct(
        private readonly CommitReportService $service,
        private readonly ?int $defaultOrganizationId = null,
        private readonly ?int $defaultTeamId = null,
        private readonly ?int $agentTaskRunId = null,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'save_commit_report';
    }

    public function getDescription(): string
    {
        return 'Persist a changelog report (ADDED / FIXED / skipped) for a git repo over a day window. Call ONCE after summarizing. Idempotent: re-saving the same repo+branch+period overwrites. Always save — even a zero-commit period — so the timeline has a row.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'repo' => ['type' => 'string', 'description' => 'Repository as owner/name.'],
                'branch' => ['type' => 'string', 'description' => 'Branch name.'],
                'period_start' => ['type' => 'string', 'description' => 'First covered date, Y-m-d (UTC).'],
                'period_end' => ['type' => 'string', 'description' => 'Last covered date, Y-m-d (UTC) = watermark.'],
                'summary' => ['type' => 'string', 'description' => 'Short narrative of what was added/fixed.'],
                'items' => ['type' => 'object', 'description' => 'Object with added[], fixed[], skipped[]. Each added/fixed entry: {sha (full 40-char), title, summary, and OPTIONAL matching: matched_issue_id (numeric issue id ONLY if confidently matched, else OMIT — unmatched is preferred over a wrong guess), matched_confidence (high|medium|low), match_source (explicit|semantic), evidence (one line why), related_issues:[{id,name}]}. skipped entry: {sha,title,reason}.'],
                'commit_shas' => ['type' => 'array', 'description' => 'All considered full SHAs.', 'items' => ['type' => 'string']],
                'total_in_window' => ['type' => 'integer', 'description' => 'Commits seen in the window before filtering.'],
                'commit_count' => ['type' => 'integer', 'description' => 'Significant commits considered.'],
                'status' => ['type' => 'string', 'description' => 'done | empty | partial.', 'enum' => ['done', 'empty', 'partial']],
            ],
            'required' => ['repo', 'branch', 'period_start', 'period_end', 'summary', 'items'],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $repo = trim((string) ($parameters['repo'] ?? ''));
        $branch = trim((string) ($parameters['branch'] ?? ''));
        $summary = trim((string) ($parameters['summary'] ?? ''));

        if ($repo === '' || $branch === '') {
            return ['success' => false, 'error' => 'repo and branch are required'];
        }

        // period_start/period_end are CALENDAR dates — normalize to Y-m-d WITHOUT timezone
        // conversion (->utc() would shift a midnight MSK date back across the day boundary).
        try {
            $periodStart = Carbon::parse((string) ($parameters['period_start'] ?? ''))->toDateString();
            $periodEnd = Carbon::parse((string) ($parameters['period_end'] ?? ''))->toDateString();
        } catch (\Throwable) {
            return ['success' => false, 'error' => 'period_start and period_end must be valid dates (Y-m-d)'];
        }

        if ($periodEnd < $periodStart) {
            return ['success' => false, 'error' => 'period_end must not be before period_start'];
        }

        $status = $parameters['status'] ?? 'done';
        if (! in_array($status, ['done', 'empty', 'partial'], true)) {
            $status = 'done';
        }

        // Normalize, then BATCH-validate matched issue ids against THIS org in ONE query:
        // hallucinated / cross-org / soft-deleted ids are rejected to unmatched, names backfilled.
        $items = $this->validateMatches($this->normalizeItems($parameters['items'] ?? []));
        $matchedCount = count(array_filter(
            array_merge($items['added'], $items['fixed']),
            static fn ($it) => ($it['matched_issue_id'] ?? null) !== null,
        ));

        $wasUpdated = $this->service->existsForWindow($repo, $branch, $periodStart, $periodEnd);

        $report = $this->service->save([
            'repo' => $repo,
            'branch' => $branch,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'summary' => $summary,
            'items' => $items,
            'commit_shas' => array_values(array_filter((array) ($parameters['commit_shas'] ?? []), 'is_string')),
            'total_in_window' => (int) ($parameters['total_in_window'] ?? 0),
            'commit_count' => (int) ($parameters['commit_count'] ?? 0),
            'status' => $status,
            'generated_by_agent_task_run_id' => $this->agentTaskRunId,
            'organization_id' => $this->defaultOrganizationId,
            'team_id' => $this->defaultTeamId,
        ]);

        return [
            'success' => true,
            'commit_report_id' => $report->id,
            'repo' => $report->repo,
            'branch' => $report->branch,
            'period_start' => $report->period_start->toDateString(),
            'period_end' => $report->period_end->toDateString(),
            'commit_count' => $report->commit_count,
            'status' => $report->status,
            'matched_count' => $matchedCount,
            'was_updated' => $wasUpdated,
            'message' => "Commit report for {$report->repo}@{$report->branch} ({$report->period_start->toDateString()}..{$report->period_end->toDateString()}) saved.",
        ];
    }

    /**
     * Coerce items to the documented shape so persisted rows stay consistent for the UI.
     * added/fixed entries require a non-empty sha (anti-hallucination contract); entries
     * without one are re-bucketed into skipped with reason "no_sha" rather than silently kept.
     */
    private function normalizeItems(mixed $items): array
    {
        $items = is_array($items) ? $items : [];
        $rejected = [];

        $added = $this->normalizeContentBucket($items['added'] ?? [], $rejected);
        $fixed = $this->normalizeContentBucket($items['fixed'] ?? [], $rejected);
        $skipped = $this->normalizeSkippedBucket($items['skipped'] ?? []);

        return [
            'added' => $added,
            'fixed' => $fixed,
            'skipped' => array_merge($skipped, $rejected),
        ];
    }

    private function normalizeContentBucket(mixed $entries, array &$rejected): array
    {
        $out = [];
        foreach ((array) $entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $sha = trim((string) ($entry['sha'] ?? ''));
            $title = (string) ($entry['title'] ?? '');
            if ($sha === '') {
                $rejected[] = ['sha' => '', 'title' => $title, 'reason' => 'no_sha'];

                continue;
            }
            $matchedId = $this->numericIdOrNull($entry['matched_issue_id'] ?? null);
            $out[] = [
                'sha' => $sha,
                'title' => $title,
                'summary' => (string) ($entry['summary'] ?? $entry['description'] ?? ''),
                'matched_issue_id' => $matchedId,
                'matched_issue_name' => isset($entry['matched_issue_name']) ? (string) $entry['matched_issue_name'] : null,
                'matched_confidence' => $this->normalizeConfidence($entry['matched_confidence'] ?? null),
                'match_source' => $this->normalizeSource($entry['match_source'] ?? null),
                'evidence' => isset($entry['evidence']) ? mb_substr((string) $entry['evidence'], 0, 1000) : null,
                'related_issues' => $this->normalizeRelatedIssues($entry['related_issues'] ?? null),
                'unmatched' => $matchedId === null,
            ];
        }

        return $out;
    }

    private function normalizeSkippedBucket(mixed $entries): array
    {
        $out = [];
        foreach ((array) $entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $out[] = [
                'sha' => trim((string) ($entry['sha'] ?? '')),
                'title' => (string) ($entry['title'] ?? ''),
                'reason' => (string) ($entry['reason'] ?? 'unspecified'),
            ];
        }

        return $out;
    }

    /**
     * Batched anti-hallucination guard: one query validates every matched_issue_id is a real,
     * non-trashed issue in THIS organization; valid ones get the authoritative name snapshot,
     * everything else is rejected to unmatched.
     */
    private function validateMatches(array $items): array
    {
        $ids = [];
        foreach (['added', 'fixed'] as $bucket) {
            foreach ($items[$bucket] ?? [] as $it) {
                if (($it['matched_issue_id'] ?? null) !== null) {
                    $ids[] = $it['matched_issue_id'];
                }
            }
        }

        $valid = collect();
        if (! empty($ids) && $this->defaultOrganizationId !== null) {
            $valid = Issue::query()
                ->withoutTrashed()
                ->inOrganization($this->defaultOrganizationId)
                ->whereKey(array_values(array_unique($ids)))
                ->get(['id', 'name'])
                ->keyBy('id');
        }

        foreach (['added', 'fixed'] as $bucket) {
            foreach ($items[$bucket] ?? [] as $i => $it) {
                $id = $it['matched_issue_id'] ?? null;
                if ($id !== null && $valid->has($id)) {
                    $items[$bucket][$i]['matched_issue_name'] = (string) $valid->get($id)->name;
                    $items[$bucket][$i]['unmatched'] = false;
                } else {
                    $items[$bucket][$i]['matched_issue_id'] = null;
                    $items[$bucket][$i]['matched_issue_name'] = null;
                    $items[$bucket][$i]['matched_confidence'] = null;
                    $items[$bucket][$i]['match_source'] = null;
                    $items[$bucket][$i]['unmatched'] = true;
                }
            }
        }

        return $items;
    }

    private function normalizeRelatedIssues(mixed $value): array
    {
        $out = [];
        foreach ((array) $value as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $id = $this->numericIdOrNull($entry['id'] ?? null);
            if ($id === null) {
                continue; // anti-hallucination: drop entries without a numeric id
            }
            $out[] = ['id' => $id, 'name' => isset($entry['name']) ? (string) $entry['name'] : null];
        }

        return $out;
    }

    private function numericIdOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    private function normalizeConfidence(mixed $value): ?string
    {
        $v = is_string($value) ? strtolower(trim($value)) : '';

        return in_array($v, ['high', 'medium', 'low'], true) ? $v : null;
    }

    private function normalizeSource(mixed $value): ?string
    {
        $v = is_string($value) ? strtolower(trim($value)) : '';

        return in_array($v, ['explicit', 'semantic'], true) ? $v : null;
    }
}
