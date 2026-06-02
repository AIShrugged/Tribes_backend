<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\OrganizationDecisionRequest;
use App\Http\Resources\API\v1\DecisionResource;
use App\Http\Responses\ApiResponse;
use App\Models\Decision;
use App\Models\Organization;
use Illuminate\Support\Facades\Gate;

class OrganizationDecisionController extends Controller
{
    public function index(OrganizationDecisionRequest $request, Organization $organization): ApiResponse
    {
        Gate::authorize('view', $organization);

        $query = Decision::where('organization_id', $organization->id)
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
}
