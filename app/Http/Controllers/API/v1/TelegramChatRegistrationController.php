<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\TelegramChatAttachCodeRequest;
use App\Http\Resources\API\v1\TelegramChatRegistrationResource;
use App\Http\Responses\ApiResponse;
use App\Models\TelegramChatRegistration;
use Illuminate\Database\Eloquent\Builder;
use App\Services\TelegramChatRegistrationService;
use App\Services\TenantScopeValidator;

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

    public function issueAttachCode(TelegramChatAttachCodeRequest $request, TelegramChatRegistration $telegramChatRegistration): ApiResponse
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

        $registration = $this->registrationService->issueAttachCode(
            $telegramChatRegistration,
            $request->user(),
            $request->getOrganizationId(),
            $request->getTeamId(),
        );

        return ApiResponse::success(data: TelegramChatRegistrationResource::make($registration));
    }
}
