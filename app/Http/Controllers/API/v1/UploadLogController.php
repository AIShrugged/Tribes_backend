<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\UploadLogRequest;
use App\Http\Resources\API\v1\TaskDataUploadDetailResource;
use App\Http\Resources\API\v1\TaskDataUploadFeedResource;
use App\Http\Resources\API\v1\TranscriptUploadDetailResource;
use App\Http\Resources\API\v1\TranscriptUploadFeedResource;
use App\Http\Responses\ApiResponse;
use App\Models\CalendarEvent;
use App\Models\ExtractionPlan;
use App\Models\Issue;
use App\Models\TaskDataUpload;
use App\Models\TranscriptUpload;
use App\Models\User;
use App\Services\Transcript\TranscriptUploadDetailService;
use App\Support\UploadStatus;
use Illuminate\Http\Request;

/**
 * Unified, org/team-wide Upload Log — merges manual transcript uploads and task-data
 * uploads into one paginated feed (discriminated by `type`) plus a per-upload detail.
 *
 * The two tables have different lifecycles/id-spaces, so the merge is done in PHP with
 * per-table bounded queries: fetch the top (offset+limit) of each, merge, sort, slice.
 * The fetch window is clamped to MAX_WINDOW so a large `offset` (limit is already capped
 * at 100) cannot force hydrating the entire org-wide history — memory is bounded at
 * 2*min(offset+limit, MAX_WINDOW) regardless of an attacker-supplied offset.
 */
class UploadLogController extends Controller
{
    /** Deepest page the in-PHP merge will serve; beyond this use filters (or a future cursor). */
    private const MAX_WINDOW = 1000;

    public function index(UploadLogRequest $request): ApiResponse
    {
        $user   = $request->user();
        $offset = $request->getOffset();
        $limit  = $request->getLimit();
        $type   = $request->input('type');
        $status = $request->input('status');
        $window = min($offset + $limit, self::MAX_WINDOW);

        $items = [];
        $count = 0;

        if ($type !== 'transcript') {
            $taskQuery = TaskDataUpload::visibleTo($user);
            if ($status !== null) {
                $taskQuery->whereIn('status', UploadStatus::rawStatusesFor($status, 'task_data'));
            }

            $taskRows = (clone $taskQuery)
                ->with(['user:id,name', 'team:id,name'])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit($window)
                ->get();

            $items = array_merge($items, TaskDataUploadFeedResource::collection($taskRows)->resolve());
            $count += $taskQuery->count();
        }

        if ($type !== 'task_data') {
            $transcriptQuery = TranscriptUpload::visibleTo($user);
            if ($status !== null) {
                $transcriptQuery->whereIn('status', UploadStatus::rawStatusesFor($status, 'transcript'));
            }

            $transcriptRows = (clone $transcriptQuery)
                ->with(['user:id,name'])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit($window)
                ->get();

            $items = array_merge($items, TranscriptUploadFeedResource::collection($transcriptRows)->resolve());
            $count += $transcriptQuery->count();
        }

        // Global sort by ISO-8601 created_at desc, with a deterministic (type, id) tiebreaker
        // so equal-timestamp rows (second-precision on pgsql) paginate stably across requests.
        usort(
            $items,
            static fn (array $a, array $b): int => [$b['created_at'], $b['type'], $b['id']]
                <=> [$a['created_at'], $a['type'], $a['id']],
        );

        $slice = array_slice($items, $offset, $limit);

        return ApiResponse::list($slice, $count);
    }

    public function show(Request $request, string $type, int $id): ApiResponse
    {
        $user = $request->user();

        return match ($type) {
            'transcript' => $this->showTranscript($user, $id),
            'task_data'  => $this->showTaskData($user, $id),
            default      => ApiResponse::notFound(),
        };
    }

    private function showTranscript(User $user, int $id): ApiResponse
    {
        $record = TranscriptUpload::visibleTo($user)
            ->with(['user:id,name', 'calendarEvent'])
            ->find($id);

        if (! $record) {
            return ApiResponse::notFound();
        }

        $resource = TranscriptUploadDetailResource::make($record);

        // Pipeline status isn't stored — derive it live from the (linked) event.
        if ($record->calendar_event_id && $record->calendarEvent) {
            $resource->processing = app(TranscriptUploadDetailService::class)->derive($record->calendarEvent);
        }

        if (UploadStatus::normalize($record->status) === UploadStatus::REVIEW && $record->calendar_event_id) {
            $resource->plan = $this->loadPlan(CalendarEvent::class, $record->calendar_event_id);
        }

        return ApiResponse::success(data: $resource);
    }

    private function showTaskData(User $user, int $id): ApiResponse
    {
        $record = TaskDataUpload::visibleTo($user)
            ->with(['user:id,name', 'team:id,name'])
            ->find($id);

        if (! $record) {
            return ApiResponse::notFound();
        }

        $resource = TaskDataUploadDetailResource::make($record);

        $normalized = UploadStatus::normalize($record->status);
        if ($normalized === UploadStatus::DONE) {
            $resource->issues = $this->visibleCreatedIssues($record, $user);
            $resource->issuesUpdated = $this->visibleUpdatedIssues($record, $user);
        } elseif ($normalized === UploadStatus::REVIEW) {
            $resource->plan = $this->loadPlan(TaskDataUpload::class, $record->id);
        }

        return ApiResponse::success(data: $resource);
    }

    /** Decoded moderation-plan payload for the upload's source, or null when no plan exists. */
    private function loadPlan(string $sourceableType, int $sourceableId): ?array
    {
        return ExtractionPlan::where('sourceable_type', $sourceableType)
            ->where('sourceable_id', $sourceableId)
            ->first()?->plan;
    }

    /**
     * Created issues for this upload, re-filtered through Issue::scopeVisibleTo so an
     * org member NOT on the team never receives issue names they couldn't otherwise see
     * (the feed row is org-wide per D4, but issue names are not).
     *
     * @return array<int, array{id: int, name: string, status: string}>
     */
    private function visibleCreatedIssues(TaskDataUpload $upload, User $user): array
    {
        return Issue::where('sourceable_type', TaskDataUpload::class)
            ->where('sourceable_id', $upload->id)
            ->visibleTo($user)
            ->get(['id', 'name'])
            ->map(static fn (Issue $issue) => [
                'id'     => $issue->id,
                'name'   => $issue->name,
                'status' => 'new',
            ])
            ->all();
    }

    /**
     * Issues this upload merely updated (snapshot of ids stored at processing time —
     * they keep their original sourceable, so there's no morph to follow). Re-filtered
     * through Issue::scopeVisibleTo for the same cross-team name protection as created
     * issues; a since-deleted id simply drops out of the whereIn.
     *
     * @return array<int, array{id: int, name: string, status: string}>
     */
    private function visibleUpdatedIssues(TaskDataUpload $upload, User $user): array
    {
        $ids = $upload->updated_issue_ids ?? [];

        if ($ids === []) {
            return [];
        }

        return Issue::whereIn('id', $ids)
            ->visibleTo($user)
            ->get(['id', 'name'])
            ->map(static fn (Issue $issue) => [
                'id'     => $issue->id,
                'name'   => $issue->name,
                'status' => 'updated',
            ])
            ->all();
    }
}
