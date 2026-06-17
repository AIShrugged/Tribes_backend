<?php

namespace App\Http\Resources\API\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One added/fixed commit_report_items row: classification + hybrid match + Pass-2 review.
 * matched and matched_task always agree (matched === (matched_task !== null)).
 */
class CommitReportItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sha' => $this->sha,
            'short_sha' => substr((string) $this->sha, 0, 7),
            'bucket' => $this->bucket,
            'title' => $this->title,
            'summary' => $this->summary,
            'position' => $this->position,
            'matched' => $this->matched_issue_id !== null,
            'unmatched' => (bool) $this->unmatched,
            'matched_task' => $this->matched_issue_id !== null
                ? [
                    'id' => $this->matched_issue_id,
                    // live name/status preferred; snapshot name as fallback when the issue is gone
                    'name' => $this->matchedIssue?->name ?? $this->matched_issue_name,
                    'status' => $this->matchedIssue?->status,
                ]
                : null,
            'matched_confidence' => $this->matched_confidence,
            'match_source' => $this->match_source,
            'evidence' => $this->evidence,
            'related_issues' => $this->related_issues ?? [],
            'architect_comment' => $this->architect_comment ?: null,
            'spec_coverage' => $this->spec_coverage,
            'review_status' => $this->review_status,
        ];
    }
}
