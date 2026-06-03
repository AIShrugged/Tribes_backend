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

    public function index(Request $request): ApiResponse
    {
        $validated = $request->validate([
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
        ]);
        $organizationId = isset($validated['organization_id']) ? (int) $validated['organization_id'] : null;

        if ($organizationId !== null) {
            $this->tenantScopeValidator->assertScopeIsValid(
                $request->user(),
                $organizationId,
                null,
                allowUnbound: false,
            );
        }

        $registrations = TelegramChatRegistration::query()
            ->with('conversation')
            ->where(function (Builder $builder): void {
                $builder->where('chat_type', '!=', 'private')
                    ->orWhereHas('conversation', function (Builder $conversation): void {
                        $conversation->where('user_id', auth()->id());
                    });
            })
            ->when($organizationId !== null, function (Builder $builder) use ($organizationId): void {
                $builder
                    ->where('organization_id', $organizationId)
                    ->where('chat_type', '!=', 'private');
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
            $request->getMessageThreadId(),
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

        // Authorize against the chat's own organization — without this any authenticated user
        // could delete another tenant's workspace chat by iterating the id (IDOR).
        $this->tenantScopeValidator->assertScopeIsValid(
            $request->user(),
            $telegramChatRegistration->organization_id,
            $telegramChatRegistration->team_id,
            allowUnbound: true,
        );
        $this->tenantScopeValidator->assertUserCanManageOrganization(
            $request->user(),
            $telegramChatRegistration->organization_id,
        );

        $this->registrationService->destroy($telegramChatRegistration);

        return ApiResponse::success();
    }
}
