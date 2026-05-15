<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\MeetingSummaryTemplateRequest;
use App\Http\Resources\API\v1\MeetingSummaryTemplateResource;
use App\Http\Resources\API\v1\MeetingSummaryTemplateVersionResource;
use App\Http\Responses\ApiResponse;
use App\Models\MeetingSummaryTemplate;
use App\Models\MeetingSummaryTemplateVersion;
use App\Models\Team;
use App\Services\Meeting\MeetingSummaryService;
use Illuminate\Support\Facades\DB;
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
     * Creates or updates the meeting summary template for a team. Each update writes the
     * previous state to {@see \App\Models\MeetingSummaryTemplateVersion} as history before
     * mutating the row, so previous configurations (sections + prompt) can be audited or
     * restored.
     *
     * @subgroup Meeting Summary Template
     * @authenticated
     */
    public function upsert(MeetingSummaryTemplateRequest $request, Team $team): ApiResponse
    {
        Gate::authorize('update', $team);

        $sections = $request->validated('sections');
        $visibleSections = $request->validated('visible_sections');
        $promptOverride = $request->validated('prompt_override');

        $template = DB::transaction(function () use ($team, $sections, $visibleSections, $promptOverride) {
            $existing = MeetingSummaryTemplate::where('team_id', $team->id)->first();

            if (! $existing) {
                return MeetingSummaryTemplate::create([
                    'team_id'          => $team->id,
                    'sections'         => $sections,
                    'visible_sections' => $visibleSections,
                    'prompt_override'  => $promptOverride,
                    'version'          => 1,
                ]);
            }

            // Snapshot the about-to-be-replaced state so editors can audit/restore later.
            $existing->versions()->create([
                'version'          => $existing->version,
                'sections'         => $existing->sections,
                'visible_sections' => $existing->visible_sections,
                'prompt_override'  => $existing->prompt_override,
                'created_at'       => now(),
            ]);

            $existing->update([
                'sections'         => $sections,
                'visible_sections' => $visibleSections,
                'prompt_override'  => $promptOverride,
                'version'          => $existing->version + 1,
            ]);

            return $existing->fresh();
        });

        return ApiResponse::success(data: MeetingSummaryTemplateResource::make($template));
    }

    /**
     * List historical versions of the team's meeting summary template.
     *
     * Newest first. Each entry is the state that was REPLACED by a later save,
     * captured by {@see self::upsert}.
     *
     * @subgroup Meeting Summary Template
     * @authenticated
     */
    public function versions(Team $team): ApiResponse
    {
        Gate::authorize('view', $team);

        $template = MeetingSummaryTemplate::where('team_id', $team->id)->first();
        if (! $template) {
            return ApiResponse::success(data: []);
        }

        $versions = $template->versions()->get();

        return ApiResponse::success(
            data: MeetingSummaryTemplateVersionResource::collection($versions)->resolve(),
        );
    }

    /**
     * Restore a previous version: copies its sections + prompt_override onto the current
     * template (which itself bumps version and writes a new history row, same as upsert).
     *
     * @subgroup Meeting Summary Template
     * @authenticated
     */
    public function restore(Team $team, int $version): ApiResponse
    {
        Gate::authorize('update', $team);

        $template = MeetingSummaryTemplate::where('team_id', $team->id)->first();
        if (! $template) {
            return ApiResponse::notFound();
        }

        $historic = MeetingSummaryTemplateVersion::query()
            ->where('template_id', $template->id)
            ->where('version', $version)
            ->first();
        if (! $historic) {
            return ApiResponse::notFound();
        }

        $template = DB::transaction(function () use ($template, $historic) {
            // Snapshot current state before overwriting (same pattern as upsert).
            $template->versions()->create([
                'version'          => $template->version,
                'sections'         => $template->sections,
                'visible_sections' => $template->visible_sections,
                'prompt_override'  => $template->prompt_override,
                'created_at'       => now(),
            ]);

            $template->update([
                'sections'         => $historic->sections,
                'visible_sections' => $historic->visible_sections,
                'prompt_override'  => $historic->prompt_override,
                'version'          => $template->version + 1,
            ]);

            return $template->fresh();
        });

        return ApiResponse::success(data: MeetingSummaryTemplateResource::make($template));
    }

    /**
     * Returns the default LLM prompt template (team-agnostic) so admins can see
     * what the system uses when no override is configured, and as a starting
     * point when writing their own.
     *
     * @subgroup Meeting Summary Template
     * @authenticated
     */
    public function defaultPrompt(): ApiResponse
    {
        return ApiResponse::success(data: [
            'default_prompt' => MeetingSummaryService::defaultPromptTemplate(),
            'placeholders'   => MeetingSummaryTemplate::PROMPT_PLACEHOLDERS,
        ]);
    }
}
