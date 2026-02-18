<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\InsightRequest;
use App\Http\Resources\API\v1\InsightItemResource;
use App\Http\Resources\API\v1\InsightProfileHistoryResource;
use App\Http\Resources\API\v1\InsightSourceResource;
use App\Http\Responses\ApiResponse;
use App\Models\InsightItem;
use App\Models\InsightProfile;
use App\Models\InsightProfileHistory;
use App\Models\InsightSource;
use App\Models\Profile;
use App\Services\Insight\InsightService;
use Illuminate\Support\Facades\Gate;

/**
 * @group Insight
 */
class InsightController extends Controller
{
    public function __construct(
        private readonly InsightService $insight,
    ) {}

    /**
     * Get full insight profile
     *
     * Returns the complete long-term AI profile for a given person, including all
     * knowledge categories, active short-term context, and relationship summaries.
     *
     * Access is granted to the profile owner or any user who hosted a meeting
     * in which this profile participated.
     *
     * @subgroup Profiles
     * @authenticated
     *
     * @urlParam profile integer required The Profile ID. Example: 42
     *
     * @response 200 scenario="OK" {
     *   "success": true,
     *   "data": {
     *     "profile_id": 42,
     *     "is_ready": true,
     *     "profiles": [
     *       {"category": "communication_style", "content": [], "version": 3, "source_count": 4, "last_updated": "2026-02-10"}
     *     ],
     *     "short_term": [
     *       {"context_type": "emotional_state", "content": [], "expires_at": "2026-02-17"}
     *     ],
     *     "relationships": [
     *       {"with": 15, "type": "collaborative", "dynamics": [], "interaction_count": 5}
     *     ]
     *   },
     *   "message": "Success",
     *   "status": 200,
     *   "meta": {}
     * }
     * @response 403 scenario="Forbidden" {
     *   "success": false,
     *   "data": null,
     *   "message": "This action is unauthorized.",
     *   "status": 403,
     *   "meta": {}
     * }
     * @response 404 scenario="Not Found" {
     *   "success": false,
     *   "data": null,
     *   "message": "Not Found",
     *   "status": 404,
     *   "meta": {}
     * }
     */
    public function profile(InsightRequest $request, Profile $profile): ApiResponse
    {
        Gate::authorize('view', $profile);

        return ApiResponse::success(data: $this->insight->getFullProfile($profile->id));
    }

    /**
     * Get active short-term context
     *
     * Returns only the current (non-expired) short-term memory entries grouped
     * by context type. Useful for surfacing recent emotional state, decisions, or
     * project context before a meeting.
     *
     * @subgroup Profiles
     * @authenticated
     *
     * @urlParam profile integer required The Profile ID. Example: 42
     *
     * @response 200 scenario="OK" {
     *   "success": true,
     *   "data": {
     *     "emotional_state": ["Seems stressed about the upcoming deadline"],
     *     "current_projects": ["Working on Q1 OKR review"],
     *     "recent_decisions": ["Decided to postpone the infrastructure migration"]
     *   },
     *   "message": "Success",
     *   "status": 200,
     *   "meta": {}
     * }
     * @response 403 scenario="Forbidden" {
     *   "success": false,
     *   "data": null,
     *   "message": "This action is unauthorized.",
     *   "status": 403,
     *   "meta": {}
     * }
     * @response 404 scenario="Not Found" {
     *   "success": false,
     *   "data": null,
     *   "message": "Not Found",
     *   "status": 404,
     *   "meta": {}
     * }
     */
    public function shortTerm(InsightRequest $request, Profile $profile): ApiResponse
    {
        Gate::authorize('view', $profile);

        return ApiResponse::success(data: $this->insight->getShortTermContext($profile->id));
    }

    /**
     * List raw insight facts (items)
     *
     * Returns a paginated list of individual facts extracted from meeting transcripts
     * for a given profile. Can be filtered by knowledge category and archive status.
     *
     * @subgroup Profiles
     * @authenticated
     *
     * @urlParam profile integer required The Profile ID. Example: 42
     *
     * @queryParam category string Filter by knowledge category. Allowed values:
     *   `communication_style`, `work_patterns`, `strengths`, `development_areas`,
     *   `goals_motivations`, `psychological_profile`. Example: communication_style
     * @queryParam is_archived boolean Filter by archive status. Omit to return all.
     *   Example: false
     * @queryParam offset integer Number of items to skip. Example: 0
     * @queryParam limit integer Number of items to return (max 50). Example: 25
     *
     * @response 200 scenario="OK" {
     *   "success": true,
     *   "data": [
     *     {
     *       "id": 101,
     *       "profile_id": 42,
     *       "category": "communication_style",
     *       "fact": "Prefers direct, concise communication without lengthy preambles.",
     *       "confidence": 0.92,
     *       "is_archived": false,
     *       "source_id": 7,
     *       "created_at": "2026-02-10T20:00:00.000000Z",
     *       "updated_at": "2026-02-10T20:00:00.000000Z"
     *     }
     *   ],
     *   "message": "Success",
     *   "status": 200,
     *   "meta": {}
     * }
     * @response 403 scenario="Forbidden" {
     *   "success": false,
     *   "data": null,
     *   "message": "This action is unauthorized.",
     *   "status": 403,
     *   "meta": {}
     * }
     * @response 404 scenario="Not Found" {
     *   "success": false,
     *   "data": null,
     *   "message": "Not Found",
     *   "status": 404,
     *   "meta": {}
     * }
     * @response 422 scenario="Validation Error" {
     *   "message": "The category field must be a valid value.",
     *   "errors": {"category": ["The category field must be a valid value."]}
     * }
     */
    public function items(InsightRequest $request, Profile $profile): ApiResponse
    {
        Gate::authorize('view', $profile);

        $query = InsightItem::where('profile_id', $profile->id);

        if ($request->getCategory() !== null) {
            $query->where('category', $request->getCategory());
        }

        if ($request->getIsArchived() !== null) {
            $query->where('is_archived', $request->getIsArchived());
        }

        $count = $query->count();

        $items = $query
            ->orderByDesc('created_at')
            ->offset($request->getOffset())
            ->limit($request->getLimit())
            ->get();

        return ApiResponse::list(InsightItemResource::collection($items), $count);
    }

    /**
     * List processed insight sources
     *
     * Returns a paginated list of the transcript sources that have been processed
     * to build this profile. Each entry corresponds to one meeting transcript
     * analysis run. Use `source_id` to correlate with a CalendarEvent ID.
     *
     * @subgroup Profiles
     * @authenticated
     *
     * @urlParam profile integer required The Profile ID. Example: 42
     *
     * @queryParam offset integer Number of items to skip. Example: 0
     * @queryParam limit integer Number of items to return (max 50). Example: 25
     *
     * @response 200 scenario="OK" {
     *   "success": true,
     *   "data": [
     *     {
     *       "id": 7,
     *       "profile_id": 42,
     *       "source_type": "transcript",
     *       "source_id": 5,
     *       "processed_at": "2026-02-10T20:05:00.000000Z",
     *       "created_at": "2026-02-10T20:00:00.000000Z",
     *       "updated_at": "2026-02-10T20:05:00.000000Z"
     *     }
     *   ],
     *   "message": "Success",
     *   "status": 200,
     *   "meta": {}
     * }
     * @response 403 scenario="Forbidden" {
     *   "success": false,
     *   "data": null,
     *   "message": "This action is unauthorized.",
     *   "status": 403,
     *   "meta": {}
     * }
     * @response 404 scenario="Not Found" {
     *   "success": false,
     *   "data": null,
     *   "message": "Not Found",
     *   "status": 404,
     *   "meta": {}
     * }
     */
    public function sources(InsightRequest $request, Profile $profile): ApiResponse
    {
        Gate::authorize('view', $profile);

        $query = InsightSource::where('profile_id', $profile->id);

        $count = $query->count();

        $sources = $query
            ->offset($request->getOffset())
            ->limit($request->getLimit())
            ->get();

        return ApiResponse::list(InsightSourceResource::collection($sources), $count);
    }

    /**
     * List profile version history
     *
     * Returns a paginated log of every version snapshot saved for a profile's
     * knowledge categories. Each time the AI evolves a category (on new transcript
     * ingestion), the previous version is archived here. Can be filtered by category.
     *
     * @subgroup Profiles
     * @authenticated
     *
     * @urlParam profile integer required The Profile ID. Example: 42
     *
     * @queryParam category string Filter history by knowledge category. Allowed values:
     *   `communication_style`, `work_patterns`, `strengths`, `development_areas`,
     *   `goals_motivations`, `psychological_profile`. Example: strengths
     * @queryParam offset integer Number of items to skip. Example: 0
     * @queryParam limit integer Number of items to return (max 50). Example: 25
     *
     * @response 200 scenario="OK" {
     *   "success": true,
     *   "data": [
     *     {
     *       "id": 3,
     *       "insight_profile_id": 12,
     *       "category": "strengths",
     *       "content": ["Strong analytical thinking", "Effective at cross-team coordination"],
     *       "version": 2,
     *       "created_at": "2026-02-12T10:00:00.000000Z"
     *     }
     *   ],
     *   "message": "Success",
     *   "status": 200,
     *   "meta": {}
     * }
     * @response 403 scenario="Forbidden" {
     *   "success": false,
     *   "data": null,
     *   "message": "This action is unauthorized.",
     *   "status": 403,
     *   "meta": {}
     * }
     * @response 404 scenario="Not Found" {
     *   "success": false,
     *   "data": null,
     *   "message": "Not Found",
     *   "status": 404,
     *   "meta": {}
     * }
     */
    public function history(InsightRequest $request, Profile $profile): ApiResponse
    {
        Gate::authorize('view', $profile);

        $insightProfileIds = InsightProfile::where('profile_id', $profile->id)->pluck('id');

        $query = InsightProfileHistory::whereIn('insight_profile_id', $insightProfileIds)
            ->orderByDesc('created_at');

        if ($request->getCategory() !== null) {
            $query->where('category', $request->getCategory());
        }

        $count = $query->count();

        $history = $query
            ->offset($request->getOffset())
            ->limit($request->getLimit())
            ->get();

        return ApiResponse::list(InsightProfileHistoryResource::collection($history), $count);
    }

    /**
     * Get relationship between two profiles
     *
     * Returns the relationship dynamics between two specific profiles — including
     * type (collaborative, conflicting, hierarchical, neutral), extracted dynamics,
     * interaction count, and date of last interaction.
     *
     * Both profiles must be accessible by the requesting user (profile owner or
     * meeting host) for this endpoint to return data.
     *
     * @subgroup Relationships
     * @authenticated
     *
     * @queryParam profile_a integer required ID of the first profile. Example: 42
     * @queryParam profile_b integer required ID of the second profile. Example: 15
     *
     * @response 200 scenario="OK" {
     *   "success": true,
     *   "data": {
     *     "profile_id_a": 15,
     *     "profile_id_b": 42,
     *     "type": "collaborative",
     *     "dynamics": ["Frequent alignment on technical decisions", "Complementary skill sets"],
     *     "interaction_count": 7,
     *     "last_interaction": "2026-02-15"
     *   },
     *   "message": "Success",
     *   "status": 200,
     *   "meta": {}
     * }
     * @response 403 scenario="Forbidden" {
     *   "success": false,
     *   "data": null,
     *   "message": "This action is unauthorized.",
     *   "status": 403,
     *   "meta": {}
     * }
     * @response 404 scenario="Not Found" {
     *   "success": false,
     *   "data": null,
     *   "message": "Not Found",
     *   "status": 404,
     *   "meta": {}
     * }
     * @response 422 scenario="Validation Error" {
     *   "message": "The profile a field is required.",
     *   "errors": {"profile_a": ["The profile a field is required."]}
     * }
     */
    public function relationship(InsightRequest $request): ApiResponse
    {
        $profileA = Profile::findOrFail($request->getProfileAId());
        $profileB = Profile::findOrFail($request->getProfileBId());

        Gate::authorize('view', $profileA);
        Gate::authorize('view', $profileB);

        $relationship = $this->insight->getRelationship($profileA->id, $profileB->id);

        if ($relationship === null) {
            return ApiResponse::notFound();
        }

        return ApiResponse::success(data: $relationship);
    }

    /**
     * Delete all insight data for a profile
     *
     * Permanently deletes all insight data associated with a profile: facts (items),
     * long-term profiles and their history, short-term context, relationship entries,
     * and processed source records. This action is irreversible.
     *
     * Only the user who owns the profile can request data deletion.
     *
     * @subgroup Profiles
     * @authenticated
     *
     * @urlParam profile integer required The Profile ID. Example: 42
     *
     * @response 200 scenario="OK" {
     *   "success": true,
     *   "data": null,
     *   "message": "All insight data deleted.",
     *   "status": 200,
     *   "meta": {}
     * }
     * @response 403 scenario="Forbidden — not the profile owner" {
     *   "success": false,
     *   "data": null,
     *   "message": "This action is unauthorized.",
     *   "status": 403,
     *   "meta": {}
     * }
     * @response 404 scenario="Not Found" {
     *   "success": false,
     *   "data": null,
     *   "message": "Not Found",
     *   "status": 404,
     *   "meta": {}
     * }
     */
    public function forget(InsightRequest $request, Profile $profile): ApiResponse
    {
        Gate::authorize('forget', $profile);

        $this->insight->forget($profile->id);

        return ApiResponse::success(message: 'All insight data deleted.');
    }
}
