<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\CalendarEvent;
use App\Models\MeetingTaskReview;
use App\Services\Issue\MeetingTaskReviewService;
use App\Services\TenantScopeValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MeetingTaskReviewController extends Controller
{
    public function __construct(
        private readonly MeetingTaskReviewService $reviewService,
        private readonly TenantScopeValidator $tenantScopeValidator,
    ) {}

    public function latest(Request $request): ApiResponse
    {
        $validated = $request->validate([
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
        ]);

        $organizationId = (int) $validated['organization_id'];
        $this->tenantScopeValidator->assertScopeIsValid($request->user(), $organizationId, null);

        $review = MeetingTaskReview::where('organization_id', $organizationId)
            ->latest('id')
            ->first();

        $data = [
            'review' => $review ? [
                'id' => $review->id,
                'calendar_event_id' => $review->calendar_event_id,
                'status' => $review->status,
                'analyzed_count' => $review->analyzed_count,
                'error' => $review->error,
                'generated_at' => $review->created_at,
            ] : null,
            'llm_blocks' => $review?->status === 'done'
                ? $this->reviewService->getLlmBlocks($review)
                : [],
            'health_blocks' => $this->reviewService->getHealthBlocks($organizationId),
        ];

        return ApiResponse::success(data: $data);
    }

    public function show(Request $request, int $calendarEventId): ApiResponse
    {
        $event = CalendarEvent::findOrFail($calendarEventId);
        $review = MeetingTaskReview::where('calendar_event_id', $calendarEventId)->firstOrFail();

        $orgId = $review->organization_id;
        $this->tenantScopeValidator->assertScopeIsValid($request->user(), $orgId, null);

        $data = [
            'review' => [
                'id' => $review->id,
                'calendar_event_id' => $review->calendar_event_id,
                'status' => $review->status,
                'analyzed_count' => $review->analyzed_count,
                'error' => $review->error,
                'generated_at' => $review->created_at,
            ],
            'llm_blocks' => $review->status === 'done'
                ? $this->reviewService->getLlmBlocks($review)
                : [],
            'health_blocks' => $this->reviewService->getHealthBlocks($orgId),
        ];

        return ApiResponse::success(data: $data);
    }
}
