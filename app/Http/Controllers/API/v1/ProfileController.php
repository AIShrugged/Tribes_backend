<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\ProfileRequest;
use App\Http\Resources\API\v1\ProfileResource;
use App\Http\Responses\ApiResponse;
use App\Models\CalendarEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * @group Calendar
 * @subgroup Events
 */
class ProfileController extends Controller
{

    /**
     * Get profiles associated with calendar event
     *
     * @authenticated
     *
     * @param ProfileRequest $request
     * @return ApiResponse
     */
    public function index(ProfileRequest $request): ApiResponse
    {
        $calendarEvent = CalendarEvent::owned(Auth::id())
            ->findOrFail($request->getCalendarEventId());

        $profiles = $calendarEvent->profiles();

        $count = $profiles->count();

        $profiles = $profiles->offset($request->getOffset())
            ->limit($request->getLimit())
            ->get();

        return ApiResponse::list(ProfileResource::collection($profiles), $count);
    }
}
