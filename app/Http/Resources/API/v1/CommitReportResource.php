<?php

namespace App\Http\Resources\API\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A commit_reports row. Two shapes from one resource:
 *  - LIST  (controller adds withCount) -> exposes added_count / fixed_count, no child arrays.
 *  - DETAIL (controller loads reportItems) -> exposes added[] / fixed[] (child rows), no counts.
 */
class CommitReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'repo' => $this->repo,
            'branch' => $this->branch,
            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'summary' => $this->summary,
            'status' => $this->status,
            'commit_count' => $this->commit_count,
            'total_in_window' => $this->total_in_window,
            'created_at' => $this->created_at?->toIso8601String(),

            'added_count' => $this->when(isset($this->added_count), fn () => (int) $this->added_count),
            'fixed_count' => $this->when(isset($this->fixed_count), fn () => (int) $this->fixed_count),
            // run-health rollup (list path only): how many items matched a task and how many got reviewed
            'matched_count' => $this->when(isset($this->matched_count), fn () => (int) $this->matched_count),
            'reviewed_count' => $this->when(isset($this->reviewed_count), fn () => (int) $this->reviewed_count),

            'added' => $this->when(
                $this->relationLoaded('reportItems'),
                fn () => CommitReportItemResource::collection($this->reportItems->whereNull('dropped_at')->where('bucket', 'added')->values()),
            ),
            'fixed' => $this->when(
                $this->relationLoaded('reportItems'),
                fn () => CommitReportItemResource::collection($this->reportItems->whereNull('dropped_at')->where('bucket', 'fixed')->values()),
            ),
        ];
    }
}
