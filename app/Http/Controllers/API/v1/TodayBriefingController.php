<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\TodayBriefingRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Today\DailyNudgeService;
use App\Services\Today\TodayBriefingService;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Support\Facades\Auth;

#[Group('Today Briefing', 'Daily briefing page for the authenticated user.')]
class TodayBriefingController extends Controller
{
    public function __construct(
        private readonly TodayBriefingService $service,
    ) {}

    #[Endpoint(title: 'Get daily briefing', description: 'Returns aggregated daily briefing: events with summaries/reviews/tasks, carried tasks, waiting-on-you, stale items, and AI nudge.')]
    public function show(TodayBriefingRequest $request): ApiResponse
    {
        $date = $request->getDate();
        $briefing = $this->service->getBriefing(Auth::user(), $date);

        return ApiResponse::success(data: $briefing);
    }

    #[Endpoint(title: 'Generate AI nudge', description: 'Generates AI nudge for today if not cached. Returns cached nudge if available. Synchronous LLM call — may take 10-15s.')]
    public function nudge(TodayBriefingRequest $request, DailyNudgeService $nudgeService): ApiResponse
    {
        $user = Auth::user();
        $date = $request->getDate();

        // Return cached if available
        $cached = $nudgeService->getCached($user->id, $date);
        if ($cached) {
            return ApiResponse::success(data: ['nudge' => $cached]);
        }

        // Generate synchronously
        $nudge = $nudgeService->generate($user, $date);

        return ApiResponse::success(data: ['nudge' => $nudge]);
    }
}
