<?php

namespace App\Http\Controllers\API\v1;

use App\Exceptions\AppException;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\UpdateExtractionPlanRequest;
use App\Http\Responses\ApiResponse;
use App\Models\CalendarEvent;
use App\Models\ExtractionPlan;
use App\Models\TaskDataUpload;
use App\Models\TranscriptUpload;
use App\Models\User;
use App\Services\ExtractionPlan\ApproveExtractionPlanService;
use Illuminate\Http\Request;

/**
 * Pre-moderation review surface for AI-extracted tasks/decisions from MANUAL uploads.
 *
 * Every action no-ops (404) unless a plan row exists for a source the caller may see (existing
 * *Upload::visibleTo scopes). Recall uploads and Telegram-origin task-data never have a plan, so
 * this surface is invisible to them — the live pipeline runs untouched.
 *
 * Editable: issues assignee_name/due_date/priority/type; decisions text/author_name/topic/skip.
 * LOCKED: issue name/description (load-bearing for future merge-LLM matching).
 */
class ExtractionPlanController extends Controller
{
    public function __construct(
        private readonly ApproveExtractionPlanService $approveService,
    ) {}

    public function show(Request $request, string $type, int $id): ApiResponse
    {
        $plan = $this->resolvePlan($request->user(), $type, $id);
        if (! $plan) {
            return ApiResponse::notFound();
        }

        return ApiResponse::success(data: [
            'status' => $plan->status,
            'plan'   => $plan->plan,
        ]);
    }

    public function update(UpdateExtractionPlanRequest $request, string $type, int $id): ApiResponse
    {
        $plan = $this->resolvePlan($request->user(), $type, $id);
        if (! $plan) {
            return ApiResponse::notFound();
        }
        if (! $plan->isPendingReview()) {
            return ApiResponse::error(message: 'Plan is not editable', status: 409);
        }

        $planData = $plan->plan ?? [];

        if ($request->has('issues')) {
            $planData = $this->mergeIssues($planData, $request->input('issues', []));
        }
        if ($request->has('decisions')) {
            $planData = $this->mergeDecisions($planData, $request->input('decisions', []));
        }

        $plan->update(['plan' => $planData]);

        return ApiResponse::success(data: ['status' => $plan->status, 'plan' => $plan->plan]);
    }

    public function approve(Request $request, string $type, int $id): ApiResponse
    {
        $plan = $this->resolvePlan($request->user(), $type, $id);
        if (! $plan) {
            return ApiResponse::notFound();
        }

        try {
            $result = $this->approveService->approve($plan);
        } catch (AppException $e) {
            return ApiResponse::error(
                message: $e->getMessage(),
                data: ['error_code' => $e->getErrorCode() ?: 'APP_ERROR'],
                status: $e->getCode() ?: 422,
            );
        }

        return ApiResponse::success(message: 'Approved', data: $result);
    }

    public function reject(Request $request, string $type, int $id): ApiResponse
    {
        $plan = $this->resolvePlan($request->user(), $type, $id);
        if (! $plan) {
            return ApiResponse::notFound();
        }
        if (! $plan->isPendingReview()) {
            return ApiResponse::error(message: 'Plan is not awaiting review', status: 409);
        }

        $plan->forceFill([
            'status'      => ExtractionPlan::STATUS_REJECTED,
            'reviewed_by' => $request->user()?->id,
            'reviewed_at' => now(),
        ])->save();

        $this->markUploadRejected($plan);

        // Nothing was ever written to issues/decisions (compute-only) — reject is a pure status flip.
        return ApiResponse::success(message: 'Rejected');
    }

    /**
     * Resolve the plan for a visible source. Returns null (→ 404) when the source is not visible,
     * it is not a manual upload, or no plan exists.
     */
    private function resolvePlan(?User $user, string $type, int $id): ?ExtractionPlan
    {
        if (! $user) {
            return null;
        }

        if ($type === 'task_data') {
            $upload = TaskDataUpload::visibleTo($user)->find($id);

            return $upload
                ? $this->planFor(TaskDataUpload::class, $upload->id)
                : null;
        }

        if ($type === 'transcript') {
            // Plan existence is the gate (created by the manual-upload controller for any manual
            // upload, incl. attach-to-existing). Recall uploads never get a TranscriptUpload row.
            $upload = TranscriptUpload::visibleTo($user)->find($id);
            if (! $upload || ! $upload->calendar_event_id) {
                return null;
            }

            return $this->planFor(CalendarEvent::class, $upload->calendar_event_id);
        }

        return null;
    }

    private function planFor(string $sourceableType, int $sourceableId): ?ExtractionPlan
    {
        return ExtractionPlan::where('sourceable_type', $sourceableType)
            ->where('sourceable_id', $sourceableId)
            ->first();
    }

    /** Merge editable issue fields by uid; drop items absent from the payload; LOCK name/description. */
    private function mergeIssues(array $planData, array $incoming): array
    {
        $editable = ['assignee_name', 'due_date', 'priority', 'type'];
        $byUid = collect($incoming)->keyBy('uid');

        $items = collect($planData['issues']['items'] ?? [])
            ->filter(fn (array $item) => isset($item['uid']) && $byUid->has($item['uid']))
            ->map(function (array $item) use ($byUid, $editable) {
                $edit = $byUid->get($item['uid']);
                foreach ($editable as $field) {
                    if (array_key_exists($field, $edit)) {
                        $item[$field] = $edit[$field];
                    }
                }

                return $item; // name/description intentionally not editable
            })
            ->values()
            ->all();

        $planData['issues']['items'] = $items;

        // Drop merge-decisions whose target item was removed, AND propagate the user's edits of an
        // "update" item into its decision: IssueMergeService::updateIssue* reads the changed fields
        // from the DECISION (not the item), so without this an edit to an "Updates #X" row would be
        // silently lost on approve. (Create-action items are applied from the item directly, so they
        // need no propagation.)
        $survivingUids = array_column($items, 'uid');
        if (is_array($planData['issues']['decisions'] ?? null)) {
            $itemByUid = collect($items)->keyBy('uid');

            $planData['issues']['decisions'] = collect($planData['issues']['decisions'])
                ->filter(fn (array $d) => in_array($d['uid'] ?? null, $survivingUids, true))
                ->map(function (array $d) use ($itemByUid) {
                    if (($d['action'] ?? null) === 'update' && isset($d['uid']) && $itemByUid->has($d['uid'])) {
                        $item = $itemByUid->get($d['uid']);
                        $d['assignee_name'] = $item['assignee_name'] ?? null;
                        $d['due_date'] = $item['due_date'] ?? null;
                        $d['priority'] = $item['priority'] ?? null;
                    }

                    return $d;
                })
                ->values()
                ->all();
        }

        return $planData;
    }

    /** Merge editable decision fields by uid; drop items absent from the payload. */
    private function mergeDecisions(array $planData, array $incoming): array
    {
        $editable = ['text', 'author_name', 'topic', 'skip'];
        $byUid = collect($incoming)->keyBy('uid');

        $planData['decisions']['items'] = collect($planData['decisions']['items'] ?? [])
            ->filter(fn (array $item) => isset($item['uid']) && $byUid->has($item['uid']))
            ->map(function (array $item) use ($byUid, $editable) {
                $edit = $byUid->get($item['uid']);
                foreach ($editable as $field) {
                    if (array_key_exists($field, $edit)) {
                        $item[$field] = $edit[$field];
                    }
                }

                return $item;
            })
            ->values()
            ->all();

        return $planData;
    }

    private function markUploadRejected(ExtractionPlan $plan): void
    {
        $message = 'Discarded in review';

        if ($plan->sourceable_type === TaskDataUpload::class) {
            TaskDataUpload::where('id', $plan->sourceable_id)
                ->whereNotIn('status', ['done'])
                ->update(['status' => 'rejected', 'error_message' => $message]);

            return;
        }

        TranscriptUpload::where('calendar_event_id', $plan->sourceable_id)
            ->whereNotIn('status', ['done', 'failed'])
            ->update(['status' => 'rejected', 'error_message' => $message]);
    }
}
