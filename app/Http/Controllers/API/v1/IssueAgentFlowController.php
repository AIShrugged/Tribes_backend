<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Issue;
use App\Services\IssueAgentFlowService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class IssueAgentFlowController extends Controller
{
    /**
     * Resume a flow that is waiting for user input after a SMART/DoD validation.
     *
     * POST /api/v1/issues/{issue}/agent-flow/answer
     *
     * Body: { "answers": "Free-text answers to the validator's questions" }
     */
    public function answer(Request $request, int $issue, IssueAgentFlowService $service): ApiResponse
    {
        $validated = $request->validate([
            'answers' => ['required', 'string', 'max:5000'],
        ]);

        $issueModel = Issue::query()
            ->visibleTo($request->user())
            ->findOrFail($issue);

        $service->answer($issueModel, $validated['answers']);

        return ApiResponse::success();
    }
}
