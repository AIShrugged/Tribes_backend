<?php

namespace App\Http\Controllers\API\v1;

use App\Enums\FollowupScope;
use App\Enums\FollowupType;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\FollowupRequest;
use App\Http\Resources\API\v1\FollowupResource;
use App\Http\Responses\ApiResponse;
use App\Models\CalendarEvent;
use App\Models\Followup;
use App\Services\Followup\FollowupService;
use Illuminate\Http\Request;
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

    /**
     * For TESTING purposes. Remove for production
     *
     * @param Request $request
     * @return ApiResponse
     *
     * @hideFromAPIDocumentation
     */
    public function generate(Request $request): ApiResponse
    {
        $calendarEvent = CalendarEvent::owned(Auth::id())
            ->findOrFail($request->route('calendar_event_id'));

        $followup = app(FollowupService::class)->generate(
            $calendarEvent,
            FollowupScope::SHARED->value,
            FollowupType::STAYFITT_V1->value
        );

        return ApiResponse::success(
            data: $followup
        );
    }
}
