<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\CalendarEventRequest;
use App\Http\Resources\API\v1\CalendarEventResource;
use App\Http\Responses\ApiResponse;
use App\Models\CalendarEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * @group Calendar
 */
class CalendarEventController extends Controller
{
    /** @subgroup Events */
    public function index(CalendarEventRequest $request): ApiResponse
    {
        $calendarEvents = CalendarEvent::owned(Auth::id());

        $count = $calendarEvents->count();

        $calendarEvents = $calendarEvents->offset($request->getOffset())
            ->limit($request->getLimit())
            ->get();

        return ApiResponse::list(CalendarEventResource::collection($calendarEvents), $count);
    }

    /** @subgroup Events */
    public function show(CalendarEventRequest $request): ApiResponse
    {
        $calendarEvent = CalendarEvent::owned(Auth::id())
            ->find($request->getEventId());

        if (!$calendarEvent) {
            return ApiResponse::notFound();
        }

        return ApiResponse::success(data: CalendarEventResource::make($calendarEvent));
    }
}
