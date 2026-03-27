<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\MeetingReviewRequest;
use App\Http\Resources\API\v1\MeetingReviewResource;
use App\Http\Responses\ApiResponse;
use App\Models\CalendarEvent;
use App\Services\Meeting\MeetingReviewService;
use Illuminate\Support\Facades\Auth;

/**
 * @group Calendar
 */
class MeetingReviewController extends Controller
{
    /**
     * Get meeting review
     *
     * Returns the AI-generated effectiveness review for a given calendar event,
     * including an overall score, score breakdown by criteria, the key insight,
     * suggestions, participation analysis, and agenda analysis.
     *
     * @subgroup Meeting Review
     * @authenticated
     *
     * @urlParam calendar_event_id integer required The Calendar Event ID. Example: 5
     *
     * @response 200 scenario="OK" {
     *   "success": true,
     *   "data": {
     *     "id": 1,
     *     "calendar_event_id": 5,
     *     "status": "done",
     *     "score": 7.2,
     *     "score_breakdown": {"goal_clarity": 8, "participation_balance": 5, "decisions_made": 7, "time_efficiency": 8, "action_items_clarity": 8},
     *     "key_insight": "70% времени говорил один участник",
     *     "suggestions": ["Назначить таймкипера", "Фиксировать повестку заранее"],
     *     "agenda_analysis": {"had_clear_agenda": true, "discussed_topics": [], "unplanned_topics": [], "missed_topics": [], "summary": ""},
     *     "participation": [{"name": "Alice", "assessment": "Активный участник"}],
     *     "created_at": "2026-03-27T10:00:00.000000Z",
     *     "updated_at": "2026-03-27T10:05:00.000000Z"
     *   },
     *   "message": "Success",
     *   "status": 200,
     *   "meta": {}
     * }
     * @response 404 scenario="Not Found" {"success": false, "data": null, "message": "Not Found", "status": 404, "meta": {}}
     * @response 401 scenario="Unauthenticated" {"message": "Unauthenticated."}
     */
    public function show(MeetingReviewRequest $request): ApiResponse
    {
        $calendarEvent = CalendarEvent::owned(Auth::id())
            ->findOrFail($request->getCalendarEventId());

        $review = $calendarEvent->meetingReview;

        if (!$review) {
            return ApiResponse::notFound();
        }

        return ApiResponse::success(data: MeetingReviewResource::make($review));
    }

    /**
     * Generate meeting review (for testing)
     *
     * @subgroup Meeting Review
     * @authenticated
     * @hideFromAPIDocumentation
     */
    public function generate(MeetingReviewRequest $request, MeetingReviewService $service): ApiResponse
    {
        $calendarEvent = CalendarEvent::owned(Auth::id())
            ->findOrFail($request->getCalendarEventId());

        $review = $service->generate($calendarEvent);

        return ApiResponse::success(data: MeetingReviewResource::make($review));
    }
}
