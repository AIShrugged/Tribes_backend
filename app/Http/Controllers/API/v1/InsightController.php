<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Services\Insight\InsightService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InsightController extends Controller
{
    public function __construct(
        private readonly InsightService $insight,
    ) {}

    /**
     * GET /v1/insight/profiles/{profile}
     * Full profile for a given Profile.
     */
    public function profile(Profile $profile): JsonResponse
    {
        return response()->json(
            $this->insight->getFullProfile($profile->id)
        );
    }

    /**
     * GET /v1/insight/profiles/{profile}/short-term
     * Current state (active short-term memory) for a given Profile.
     */
    public function shortTerm(Profile $profile): JsonResponse
    {
        return response()->json(
            $this->insight->getShortTermContext($profile->id)
        );
    }

    /**
     * GET /v1/insight/relationships?profile_a={id}&profile_b={id}
     * Relationship dynamics between two profiles.
     */
    public function relationship(Request $request): JsonResponse
    {
        $request->validate([
            'profile_a' => 'required|integer|exists:profiles,id',
            'profile_b' => 'required|integer|exists:profiles,id',
        ]);

        $relationship = $this->insight->getRelationship(
            (int) $request->input('profile_a'),
            (int) $request->input('profile_b'),
        );

        if ($relationship === null) {
            return response()->json(['message' => 'No relationship data found.'], 404);
        }

        return response()->json($relationship);
    }

    /**
     * DELETE /v1/insight/profiles/{profile}
     * Forget all insight data for a Profile.
     */
    public function forget(Profile $profile): JsonResponse
    {
        $this->insight->forget($profile->id);

        return response()->json(['message' => 'All insight data deleted.']);
    }
}
