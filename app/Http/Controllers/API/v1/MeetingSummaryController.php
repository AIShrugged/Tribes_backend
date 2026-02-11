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
     * @subgroup Meeting Summary
     * @authenticated
     */
    public function show(MeetingSummaryRequest $request): ApiResponse
    {
        $calendarEvent = CalendarEvent::owned(Auth::id())
            ->findOrFail($request->getCalendarEventId());

        $summary = $calendarEvent->meetingSummary;

        if (!$summary) {
            return ApiResponse::notFound();
        }

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
            ->findOrFail($request->getCalendarEventId());

        $summary = $service->generate($calendarEvent);

        return ApiResponse::success(data: MeetingSummaryResource::make($summary));
    }
}
