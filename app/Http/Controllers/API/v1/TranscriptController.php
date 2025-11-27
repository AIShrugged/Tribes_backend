<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\TranscriptRequest;
use App\Http\Resources\API\v1\TranscriptEntryResource;
use App\Http\Responses\ApiResponse;
use App\Models\CalendarEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * @group Calendar
 * @subgroup Events
 */
class TranscriptController extends Controller
{
    /**
     * Get transcript of calendar event
     *
     * @authenticated
     *
     * @param TranscriptRequest $request
     * @return ApiResponse
     */
    public function index(TranscriptRequest $request): ApiResponse
    {
        $calendarEvent = CalendarEvent::owned(Auth::id())
            ->findOrFail($request->getCalendarEventId());

        $entries = $calendarEvent->transcriptEntries();

        $count = $entries->count();

        $entries = $entries->offset($request->getOffset())
            ->limit($request->getLimit())
            ->get();

        return ApiResponse::list(TranscriptEntryResource::collection($entries), $count);
    }
}
