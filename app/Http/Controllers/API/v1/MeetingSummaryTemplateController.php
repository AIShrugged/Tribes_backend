<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\MeetingSummaryTemplateRequest;
use App\Http\Resources\API\v1\MeetingSummaryTemplateResource;
use App\Http\Responses\ApiResponse;
use App\Models\MeetingSummaryTemplate;
use App\Models\Team;
use Illuminate\Support\Facades\Gate;

/**
 * @group Teams
 */
class MeetingSummaryTemplateController extends Controller
{
    /**
     * Get meeting summary template
     *
     * Returns the meeting summary template for a team, or 404 if none configured.
     *
     * @subgroup Meeting Summary Template
     * @authenticated
     */
    public function show(Team $team): ApiResponse
    {
        Gate::authorize('view', $team);

        $template = MeetingSummaryTemplate::where('team_id', $team->id)->first();

        return $template
            ? ApiResponse::success(data: MeetingSummaryTemplateResource::make($template))
            : ApiResponse::notFound();
    }

    /**
     * Upsert meeting summary template
     *
     * Creates or updates the meeting summary template for a team.
     *
     * @subgroup Meeting Summary Template
     * @authenticated
     */
    public function upsert(MeetingSummaryTemplateRequest $request, Team $team): ApiResponse
    {
        Gate::authorize('update', $team);

        $template = MeetingSummaryTemplate::updateOrCreate(
            ['team_id' => $team->id],
            ['sections' => $request->validated('sections')],
        );

        return ApiResponse::success(data: MeetingSummaryTemplateResource::make($template));
    }
}
