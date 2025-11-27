<?php

namespace App\Http\Controllers\API\v1;

use App\Events\CalendarEventChanged;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\RequireBotRequest;
use App\Http\Resources\API\v1\CalendarEventResource;
use App\Http\Responses\ApiResponse;
use App\Models\CalendarEvent;
use Illuminate\Support\Facades\Auth;

/**
 * @group Calendar
 * @subgroup Events
 */
class BotController extends Controller
{
    /**
     * Require bot for event
     *
     * @authenticated
     *
     * @param RequireBotRequest $request
     * @return ApiResponse
     */
    public function require(RequireBotRequest $request): ApiResponse
    {
        $calendarEvent = CalendarEvent::owned(Auth::id())
            ->findOrFail($request->getCalendarEventId());

        $calendarEvent->requiredBot($request->getRequiredBot());

        CalendarEventChanged::dispatch($calendarEvent);

        return ApiResponse::success(
            data: CalendarEventResource::make($calendarEvent),
        );
    }
}
