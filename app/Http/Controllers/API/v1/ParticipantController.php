<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\ParticipantRequest;
use App\Http\Resources\API\v1\ParticipantResource;
use App\Http\Responses\ApiResponse;
use App\Models\CalendarEvent;
use Illuminate\Support\Facades\Auth;

/**
 * @group Calendar
 * @subgroup Events
 */
class ParticipantController extends Controller
{
    /**
     * Get participants of calendar event
     *
     * @authenticated
     *
     * @param ParticipantRequest $request
     * @return ApiResponse
     */
    public function index(ParticipantRequest $request): ApiResponse
    {
        $calendarEvent = CalendarEvent::owned(Auth::id())->findOrFail($request->getCalendarEventId());

        $participants = $calendarEvent->participants();

        $count = $participants->count();

        $participants = $participants->offset($request->getOffset())
            ->limit($request->getLimit())
            ->get();

        return ApiResponse::list(ParticipantResource::collection($participants), $count);
    }

    /**
     * Attach event participant to profile
     *
     * @authenticated
     *
     * @param ParticipantRequest $request
     * @return ApiResponse
     */
    public function setProfile(ParticipantRequest $request): ApiResponse
    {
        $calendarEvent = CalendarEvent::owned(Auth::id())->findOrFail($request->getCalendarEventId());

        $participant = $calendarEvent->participants()->findOrFail($request->getParticipantId());

        $profile = $calendarEvent->profiles()->findOrFail($request->getProfileId());

        $participant->profile()->associate($profile);
        $participant->save();

        return ApiResponse::success(
            data: ParticipantResource::make($participant),
        );
    }
}
