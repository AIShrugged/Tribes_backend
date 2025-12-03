<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\FollowupRequest;
use App\Http\Resources\API\v1\FollowupResource;
use App\Http\Responses\ApiResponse;
use App\Models\CalendarEvent;
use App\Models\Followup;
use Illuminate\Support\Facades\Auth;

class FollowupController extends Controller
{
    public function index(FollowupRequest $request): ApiResponse
    {
        $calendarEvent = CalendarEvent::owned(Auth::id())
            ->findOrFail($request->getCalendarEventId());

        $followups = $calendarEvent->followups()->owned(Auth::id());

        $count = $followups->count();

        $followups = $followups->offset($request->getOffset())
            ->limit($request->getLimit())
            ->get();

        return ApiResponse::list(FollowupResource::collection($followups), $count);
    }

    public function show(FollowupRequest $request): ApiResponse
    {
        $followup = Followup::owned(Auth::id())
            ->findOrFail($request->getFollowupId());

        return ApiResponse::success(
            data: FollowupResource::make($followup)
        );
    }
}
