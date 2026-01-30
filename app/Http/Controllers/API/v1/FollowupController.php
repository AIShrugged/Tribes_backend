<?php

namespace App\Http\Controllers\API\v1;

use App\Exceptions\AppException;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\FollowupRequest;
use App\Http\Resources\API\v1\FollowupResource;
use App\Http\Responses\ApiResponse;
use App\Models\CalendarEvent;
use App\Models\Followup;
use App\Models\Team;
use App\Services\Followup\FollowupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class FollowupController extends Controller
{
    public function index(FollowupRequest $request, Team $team): ApiResponse
    {
        Gate::authorize('viewAny', [Followup::class, $team]);

        $followups = $team->followups()->owned(Auth::id());

        $count = $followups->count();

        $followups = $followups->offset($request->getOffset())
            ->limit($request->getLimit())
            ->get();

        return ApiResponse::list(FollowupResource::collection($followups), $count);
    }

    public function show(FollowupRequest $request, Followup $followup): ApiResponse
    {
        Gate::authorize('view', $followup);

        return ApiResponse::success(
            data: FollowupResource::make($followup)
        );
    }

    public function eventShow(FollowupRequest $request, CalendarEvent $event): ApiResponse
    {
        $followup = $event->followups()->owned(Auth::id())->latest()->firstOrFail();

        Gate::authorize('view', $followup);

        return ApiResponse::success(data: FollowupResource::make($followup));
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

        $user = Auth::user();
        $teams = $user->teams;

        if ($teams->isEmpty()) {
            throw new AppException('User has no teams', 'NO_TEAMS');
        }

        // Для теста генерируем для первой команды
        $team = $teams->first();

        $followup = app(FollowupService::class)->generate(
            $calendarEvent,
            $team,
            $user
        );

        return ApiResponse::success(
            data: $followup
        );
    }
}
