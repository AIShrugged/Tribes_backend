<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\MeetingSummaryRequest;
use App\Http\Resources\API\v1\MeetingSummaryResource;
use App\Http\Responses\ApiResponse;
use App\Models\CalendarEvent;
use App\Services\Meeting\MeetingSummaryService;
use Illuminate\Support\Facades\Auth;

/**
 * @group Calendar
 */
class MeetingSummaryController extends Controller
{
    /**
     * Get meeting summary
     *
     * Returns the AI-generated summary for a given calendar event, including
     * a human-readable title, a narrative summary, a list of key discussion points,
     * and a list of decisions made. Returns 404 if the summary has not been
     * generated yet or the event does not belong to the authenticated user.
     *
     * @subgroup Meeting Summary
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
     *     "title": "Q1 Planning Sync",
     *     "summary": "The team aligned on Q1 priorities and confirmed the product roadmap.",
     *     "key_points": [
     *       "Marketing budget approved for Q1 campaigns",
     *       "Engineering capacity confirmed at 80% for feature work"
     *     ],
     *     "decisions": [
     *       "Launch the redesign by March 1st",
     *       "Hire two senior engineers in Q1"
     *     ],
     *     "tracker_url": "https://tracker.example.com/board/123",
     *     "tasks": [
     *       {
     *         "id": 10,
     *         "name": "Update onboarding flow",
     *         "status": "open"
     *       }
     *     ],
     *     "created_at": "2026-02-10T20:00:00.000000Z",
     *     "updated_at": "2026-02-10T20:05:00.000000Z"
     *   },
     *   "message": "Success",
     *   "status": 200,
     *   "meta": {}
     * }
     * @response 404 scenario="Not Found — no summary yet or wrong event" {
     *   "success": false,
     *   "data": null,
     *   "message": "Not Found",
     *   "status": 404,
     *   "meta": {}
     * }
     * @response 401 scenario="Unauthenticated" {"message": "Unauthenticated."}
     */
    public function show(MeetingSummaryRequest $request): ApiResponse
    {
        $calendarEvent = CalendarEvent::owned(Auth::id())
            ->with('issues')
            ->findOrFail($request->getCalendarEventId());

        $summary = $calendarEvent->meetingSummary;

        if (!$summary) {
            return ApiResponse::notFound();
        }

        // Expose the eager-loaded issues collection on the summary under the
        // relation name used by MeetingSummaryResource ('calendarEventIssues').
        $summary->setRelation('calendarEventIssues', $calendarEvent->issues);

        return ApiResponse::success(data: MeetingSummaryResource::make($summary));
    }

    /**
     * Generate meeting summary (for testing)
     *
     * @subgroup Meeting Summary
     * @authenticated
     * @hideFromAPIDocumentation
     */
    public function generate(MeetingSummaryRequest $request, MeetingSummaryService $service): ApiResponse
    {
        $calendarEvent = CalendarEvent::owned(Auth::id())
            ->with('issues')
            ->findOrFail($request->getCalendarEventId());

        $summary = $service->generate($calendarEvent);

        // Expose the eager-loaded issues collection on the summary under the
        // relation name used by MeetingSummaryResource ('calendarEventIssues').
        $summary->setRelation('calendarEventIssues', $calendarEvent->issues);

        return ApiResponse::success(data: MeetingSummaryResource::make($summary));
    }
}