<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\AgendaTemplateRequest;
use App\Http\Resources\API\v1\AgendaTemplateResource;
use App\Http\Responses\ApiResponse;
use App\Models\AgendaTemplate;
use App\Models\Team;
use Illuminate\Support\Facades\Gate;

/**
 * @group Teams
 */
class AgendaTemplateController extends Controller
{
    /**
     * Get agenda template
     *
     * Returns the agenda template for a team, or default sections if none configured.
     *
     * @subgroup Agenda Template
     * @authenticated
     */
    public function show(Team $team): ApiResponse
    {
        Gate::authorize('view', $team);

        $template = AgendaTemplate::where('team_id', $team->id)->first();

        if (! $template) {
            return ApiResponse::success(data: [
                'id'                 => null,
                'team_id'            => $team->id,
                'sections'           => AgendaTemplate::DEFAULT_SECTIONS,
                'available_sections' => AgendaTemplate::DEFAULT_SECTIONS,
                'is_default'         => true,
            ]);
        }

        return ApiResponse::success(data: AgendaTemplateResource::make($template));
    }

    /**
     * Upsert agenda template
     *
     * Creates or updates the agenda template for a team.
     *
     * @subgroup Agenda Template
     * @authenticated
     */
    public function upsert(AgendaTemplateRequest $request, Team $team): ApiResponse
    {
        Gate::authorize('update', $team);

        $template = AgendaTemplate::updateOrCreate(
            ['team_id' => $team->id],
            ['sections' => $request->validated('sections')],
        );

        return ApiResponse::success(data: AgendaTemplateResource::make($template));
    }
}
