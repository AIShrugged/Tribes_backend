<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\TeamNotificationSettingRequest;
use App\Http\Resources\API\v1\TeamNotificationSettingResource;
use App\Http\Responses\ApiResponse;
use App\Models\Team;
use App\Models\TeamNotificationSetting;
use App\Services\TeamNotificationSettingService;
use Illuminate\Support\Facades\Gate;

class TeamNotificationSettingController extends Controller
{
    public function __construct(
        private readonly TeamNotificationSettingService $service,
    ) {}

    public function index(TeamNotificationSettingRequest $request, Team $team): ApiResponse
    {
        Gate::authorize('viewAny', [TeamNotificationSetting::class, $team]);

        $settings = $team->notificationSettings()->with('notifiable')->get();

        return ApiResponse::list(TeamNotificationSettingResource::collection($settings), $settings->count());
    }

    public function store(TeamNotificationSettingRequest $request, Team $team): ApiResponse
    {
        Gate::authorize('create', [TeamNotificationSetting::class, $team]);

        $setting = $this->service->create(
            $team,
            $request->getEventType(),
            $request->getChannelType(),
            $request->getTelegramChatRegistrationId(),
            $request->isEnabled(),
        );

        return ApiResponse::success(data: TeamNotificationSettingResource::make($setting->load('notifiable')));
    }

    public function sync(TeamNotificationSettingRequest $request, Team $team): ApiResponse
    {
        Gate::authorize('create', [TeamNotificationSetting::class, $team]);

        $settings = $this->service->sync(
            $team,
            $request->getEventType(),
            $request->getChannelType(),
            $request->getChatRegistrationIds(),
        );

        return ApiResponse::list(
            TeamNotificationSettingResource::collection($settings),
            $settings->count(),
        );
    }

    public function setEnabled(TeamNotificationSettingRequest $request, Team $team): ApiResponse
    {
        Gate::authorize('create', [TeamNotificationSetting::class, $team]);

        $settings = $this->service->setEventEnabled(
            $team,
            $request->getEventType(),
            $request->getChannelType(),
            $request->boolean('enabled'),
        );

        return ApiResponse::list(
            TeamNotificationSettingResource::collection($settings),
            $settings->count(),
        );
    }

    public function setMinutesBefore(TeamNotificationSettingRequest $request, Team $team): ApiResponse
    {
        Gate::authorize('create', [TeamNotificationSetting::class, $team]);

        $settings = $this->service->setEventMinutesBefore(
            $team,
            $request->getEventType(),
            $request->getChannelType(),
            $request->getMinutesBefore(),
        );

        return ApiResponse::list(
            TeamNotificationSettingResource::collection($settings),
            $settings->count(),
        );
    }

    public function update(TeamNotificationSettingRequest $request, Team $team, TeamNotificationSetting $setting): ApiResponse
    {
        abort_if($setting->team_id !== $team->id, 404);
        Gate::authorize('update', $setting);

        $setting = $this->service->update($setting, $request->isEnabled(), $request->getMinutesBefore());

        return ApiResponse::success(data: TeamNotificationSettingResource::make($setting->load('notifiable')));
    }

    public function destroy(TeamNotificationSettingRequest $request, Team $team, TeamNotificationSetting $setting): ApiResponse
    {
        abort_if($setting->team_id !== $team->id, 404);
        Gate::authorize('destroy', $setting);

        $setting->delete();

        return ApiResponse::success();
    }
}
