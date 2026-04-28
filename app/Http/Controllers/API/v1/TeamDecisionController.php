<?php

namespace App\Http\Controllers\API\v1;

use App\Enums\DecisionSourceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\TeamDecisionRequest;
use App\Http\Resources\API\v1\DecisionResource;
use App\Http\Responses\ApiResponse;
use App\Models\Decision;
use App\Models\Team;
use Illuminate\Support\Facades\Gate;

class TeamDecisionController extends Controller
{
    public function index(TeamDecisionRequest $request, Team $team): ApiResponse
    {
        Gate::authorize('view', $team);

        $query = Decision::where('team_id', $team->id)
            ->with(['authorUser', 'calendarEvent'])
            ->orderByDesc('created_at');

        if ($sourceType = $request->getSourceType()) {
            $query->where('source_type', $sourceType);
        }

        if ($search = $request->getSearch()) {
            $query->whereRaw(
                'search_vector @@ plainto_tsquery(\'simple\', ?)',
                [$search],
            );
        }

        $count = $query->count();

        $decisions = $query
            ->offset($request->getOffset())
            ->limit($request->getLimit())
            ->get();

        return ApiResponse::list(DecisionResource::collection($decisions), $count);
    }

    public function store(TeamDecisionRequest $request, Team $team): ApiResponse
    {
        Gate::authorize('view', $team);

        $user = $request->user();

        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $request->text);

        $decision = Decision::create([
            'team_id'          => $team->id,
            'organization_id'  => $team->organization_id,
            'source_type'      => DecisionSourceType::Manual->value,
            'text'             => $text,
            'topic'            => $request->topic,
            'author_user_id'   => $user->id,
            'author_raw_name'  => $user->name,
        ]);

        $decision->load('authorUser');

        return ApiResponse::success('Decision saved', DecisionResource::make($decision), 201);
    }
}
