<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Services\Insight\InsightService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InsightController extends Controller
{
    public function __construct(
        private readonly InsightService $insight,
    ) {}

    /**
     * GET /v1/insight/profiles/{email}
     * Full profile for an email address.
     */
    public function profile(string $email): JsonResponse
    {
        $profile = $this->insight->getFullProfile($email);

        return response()->json($profile);
    }

    /**
     * GET /v1/insight/profiles/{email}/short-term
     * Current state (active short-term memory) for an email.
     */
    public function shortTerm(string $email): JsonResponse
    {
        $context = $this->insight->getShortTermContext($email);

        return response()->json($context);
    }

    /**
     * GET /v1/insight/relationships?email_a=...&email_b=...
     * Relationship dynamics between two people.
     */
    public function relationship(Request $request): JsonResponse
    {
        $request->validate([
            'email_a' => 'required|email',
            'email_b' => 'required|email',
        ]);

        $relationship = $this->insight->getRelationship(
            $request->string('email_a'),
            $request->string('email_b'),
        );

        if ($relationship === null) {
            return response()->json(['message' => 'No relationship data found.'], 404);
        }

        return response()->json($relationship);
    }

    /**
     * DELETE /v1/insight/profiles/{email}
     * Forget all insight data for an email.
     */
    public function forget(string $email): JsonResponse
    {
        $this->insight->forget($email);

        return response()->json(['message' => 'All insight data deleted.']);
    }
}
