<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\TelegramWorkspaceChatCreateRequest;
use App\Http\Resources\API\v1\TelegramChatRegistrationResource;
use App\Http\Responses\ApiResponse;
use App\Models\TelegramChatRegistration;
use App\Services\TelegramChatRegistrationService;
use App\Services\TenantScopeValidator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class TelegramChatRegistrationController extends Controller
{
    public function __construct(
        private readonly TelegramChatRegistrationService $registrationService,
        private readonly TenantScopeValidator $tenantScopeValidator,
    ) {}

    public function index(): ApiResponse
    {
        $registrations = TelegramChatRegistration::query()
            ->where(function (Builder $builder): void {
                $builder->where('chat_type', '!=', 'private')
                    ->orWhereHas('conversation', function (Builder $conversation): void {
                        $conversation->where('user_id', auth()->id());
                    });
            })
            ->latest('id')
            ->get();

        return ApiResponse::list(TelegramChatRegistrationResource::collection($registrations), $registrations->count());
    }

    public function store(TelegramWorkspaceChatCreateRequest $request): ApiResponse
    {
        $this->tenantScopeValidator->assertScopeIsValid(
            $request->user(),
            $request->getOrganizationId(),
            $request->getTeamId(),
            allowUnbound: false,
        );
        $this->tenantScopeValidator->assertUserCanManageOrganization(
            $request->user(),
            $request->getOrganizationId(),
        );

        $registration = $this->registrationService->createWorkspaceChat(
            $request->getName(),
            $request->getTelegramChatId(),
            $request->getOrganizationId(),
            $request->getTeamId(),
            $request->user(),
        );

        return ApiResponse::success(data: TelegramChatRegistrationResource::make($registration));
    }

    public function destroy(Request $request, TelegramChatRegistration $telegramChatRegistration): ApiResponse
    {
        if ($telegramChatRegistration->chat_type === 'private') {
            return ApiResponse::error('Private chats cannot be removed from the workspace chat list.', status: 422);
        }

        $this->registrationService->destroy($telegramChatRegistration);

        return ApiResponse::success();
    }
}
