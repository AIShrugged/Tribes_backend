<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\API\v1\IssueResource;
use App\Models\Profile;
use App\Services\UserFocusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FocusedIssuesController extends Controller
{
    public function __construct(private readonly UserFocusService $userFocusService) {}

    public function index(Request $request): JsonResponse
    {
        $user    = $request->user();
        $profile = Profile::where('user_id', $user->id)->first();

        $hasFocus  = false;
        $focusText = '';

        if ($profile) {
            $focus = $this->userFocusService->getFocus($profile);
            if ($focus && ! empty($focus->content['focus_text'])) {
                $hasFocus  = true;
                $focusText = $focus->content['focus_text'];
            }
        }

        if (! $hasFocus) {
            return response()->json([
                'success' => true,
                'data'    => [],
                'message' => '',
                'status'  => 200,
                'meta'    => ['has_focus' => false, 'focus_text' => null],
            ]);
        }

        $issues = $this->userFocusService->getFocusedIssues($user);

        return response()->json([
            'success' => true,
            'data'    => IssueResource::collection($issues),
            'message' => '',
            'status'  => 200,
            'meta'    => [
                'has_focus'     => true,
                'focus_text'    => $focusText,
                'matched_count' => $issues->count(),
            ],
        ]);
    }
}
