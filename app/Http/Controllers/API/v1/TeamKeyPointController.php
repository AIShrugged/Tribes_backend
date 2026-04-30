<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\TeamKeyPointRequest;
use App\Http\Resources\API\v1\MeetingKeyPointResource;
use App\Http\Responses\ApiResponse;
use App\Models\MeetingKeyPoint;
use App\Models\Team;
use Illuminate\Support\Facades\Gate;

class TeamKeyPointController extends Controller
{
    public function index(TeamKeyPointRequest $request, Team $team): ApiResponse
    {
        Gate::authorize('view', $team);

        $query = MeetingKeyPoint::where('team_id', $team->id)
            ->with('calendarEvent')
            ->orderByDesc('created_at')
            ->orderBy('position');

        if ($search = $request->getSearch()) {
            $query->whereRaw(
                "search_vector @@ plainto_tsquery('simple', ?)",
                [$search],
            );
        }

        $count = $query->count();

        $items = $query
            ->offset($request->getOffset())
            ->limit($request->getLimit())
            ->get();

        return ApiResponse::list(MeetingKeyPointResource::collection($items), $count);
    }
}