<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\CommitReportIndexRequest;
use App\Http\Resources\API\v1\CommitReportResource;
use App\Http\Responses\ApiResponse;
use App\Models\CommitReport;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CommitReportController extends Controller
{
    public function index(CommitReportIndexRequest $request, Organization $organization): ApiResponse
    {
        Gate::authorize('view', $organization);

        $query = CommitReport::where('organization_id', $organization->id)
            ->orderByDesc('period_end')
            ->orderByDesc('period_start')
            ->orderByDesc('id');

        if (! $request->includeEmpty()) {
            $query->meaningful();
        }
        if ($repo = $request->getRepo()) {
            $query->where('repo', $repo);
        }
        if ($branch = $request->getBranch()) {
            $query->where('branch', $branch);
        }
        if ($from = $request->getFrom()) {
            $query->whereDate('period_end', '>=', $from);
        }
        if ($to = $request->getTo()) {
            $query->whereDate('period_start', '<=', $to);
        }

        $count = $query->count();

        $reports = $query
            ->withCount([
                // exclude tombstoned (dropped-on-re-save) orphans from every rollup
                'reportItems as added_count' => fn ($q) => $q->whereNull('dropped_at')->where('bucket', 'added'),
                'reportItems as fixed_count' => fn ($q) => $q->whereNull('dropped_at')->where('bucket', 'fixed'),
                'reportItems as matched_count' => fn ($q) => $q->whereNull('dropped_at')->whereNotNull('matched_issue_id'),
                'reportItems as reviewed_count' => fn ($q) => $q->whereNull('dropped_at')->where('review_status', 'done'),
            ])
            ->offset($request->getOffset())
            ->limit($request->getLimit())
            ->get();

        return ApiResponse::list(CommitReportResource::collection($reports), $count);
    }

    public function show(Request $request, Organization $organization, CommitReport $commitReport): ApiResponse
    {
        Gate::authorize('view', $organization);
        abort_unless($commitReport->organization_id === $organization->id, 404);

        $commitReport->load([
            'reportItems' => fn ($q) => $q->whereNull('dropped_at')->orderBy('bucket')->orderBy('position'),
            'reportItems.matchedIssue',
        ]);

        return ApiResponse::success('OK', new CommitReportResource($commitReport));
    }
}
