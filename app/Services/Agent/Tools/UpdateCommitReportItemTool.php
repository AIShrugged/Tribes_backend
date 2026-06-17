<?php

namespace App\Services\Agent\Tools;

use App\Services\CommitReport\CommitReportService;

class UpdateCommitReportItemTool extends AbstractAgentTool
{
    public function __construct(
        private readonly CommitReportService $service,
        private readonly ?int $organizationId = null,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'update_commit_report_item';
    }

    public function getDescription(): string
    {
        return 'Write the deep-review result (architect comment + spec coverage) onto ONE commit report item, identified by commit_report_id + sha — both given to you in your task input. Call this EXACTLY ONCE at the end of a per-commit review. Race-safe and idempotent.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'commit_report_id' => ['type' => 'integer', 'description' => 'From your task input.'],
                'sha' => ['type' => 'string', 'description' => 'The commit SHA you reviewed (from your task input).'],
                'architect_comment' => ['type' => 'object', 'description' => 'Short architect notes: {good, bad, improve, covers_spec}.'],
                'spec_coverage' => ['type' => 'string', 'description' => 'covered | partial | uncovered | unknown (unknown if the issue has no spec to check against).', 'enum' => ['covered', 'partial', 'uncovered', 'unknown']],
            ],
            'required' => ['commit_report_id', 'sha', 'architect_comment', 'spec_coverage'],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $parameters = $parameters ?? [];
        $reportId = (int) ($parameters['commit_report_id'] ?? 0);
        $sha = trim((string) ($parameters['sha'] ?? ''));

        if ($reportId <= 0 || $sha === '') {
            return ['success' => false, 'error' => 'commit_report_id and sha are required'];
        }

        $coverage = $parameters['spec_coverage'] ?? 'unknown';
        if (! in_array($coverage, ['covered', 'partial', 'uncovered', 'unknown'], true)) {
            $coverage = 'unknown';
        }

        $found = $this->service->applyDeepReview($reportId, $sha, [
            'architect_comment' => $this->normalizeArchitectComment($parameters['architect_comment'] ?? null),
            'spec_coverage' => $coverage,
        ], $this->organizationId);

        return [
            'success' => true,
            'was_found' => $found,
            'commit_report_id' => $reportId,
            'sha' => $sha,
            'message' => $found
                ? 'Review saved.'
                : 'No matching item for this commit_report_id + sha (or wrong organization) — nothing written.',
        ];
    }

    private function normalizeArchitectComment(mixed $value): array
    {
        $value = is_array($value) ? $value : [];

        return [
            'good' => isset($value['good']) ? (string) $value['good'] : '',
            'bad' => isset($value['bad']) ? (string) $value['bad'] : '',
            'improve' => isset($value['improve']) ? (string) $value['improve'] : '',
            'covers_spec' => isset($value['covers_spec']) ? (string) $value['covers_spec'] : '',
        ];
    }
}
