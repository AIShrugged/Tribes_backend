<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\ChatRequest;
use App\Http\Resources\API\v1\ChatResource;
use App\Http\Responses\ApiResponse;
use App\Models\Chat;
use App\Services\Chat\ChatService;
use App\Services\TenantScopeValidator;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;

#[Group('Tribes Chat', 'AI chat sessions and conversation containers.')]
class ChatController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly ChatService $chatService,
        private readonly TenantScopeValidator $tenantScopeValidator,
    ) {
        $this->authorizeResource(Chat::class, 'chat');
    }

    /**
     * List chats
     *
     * Returns a paginated list of AI chats belonging to the authenticated user.
     * The total count is returned in the `Items-Count` response header.
     *
     * @authenticated
     *
     *
     * @response 200 scenario="OK" {
     *   "success": true,
     *   "data": [
     *     {
     *       "id": 1,
     *       "title": "Q1 Strategy Discussion",
     *       "created_at": "2026-02-10T20:00:00.000000Z",
     *       "updated_at": "2026-02-10T20:05:00.000000Z"
     *     }
     *   ],
     *   "message": "Success",
     *   "status": 200,
     *   "meta": {}
     * }
     * @response 401 scenario="Unauthenticated" {"message": "Unauthenticated."}
     */
    #[Endpoint(title: 'List chats', description: 'Return paginated chats belonging to the authenticated user.')]
    #[QueryParameter('organization_id', 'Organization ID to list chats for.', required: true, type: 'integer', example: 1)]
    #[Response(
        200,
        'Chat list envelope.',
        type: 'array{success: bool, data: array<int, \App\Http\Resources\API\v1\ChatResource>, message: string, status: int, meta: array<string, mixed>}'
    )]
    public function index(ChatRequest $request): ApiResponse
    {
        $user = Auth::user();
        $organizationId = $request->getOrganizationId();

        $this->tenantScopeValidator->assertScopeIsValid(
            $user,
            $organizationId,
            null,
            false,
        );

        $count = $this->chatService->countChatsForUser($user, $organizationId);
        $chats = $this->chatService->getChatsForUser(
            $user,
            $organizationId,
            $request->getOffset(),
            $request->getLimit()
        );

        return ApiResponse::list(ChatResource::collection($chats), $count);
    }

    /**
     * Create chat
     *
     * Creates a new AI chat session for the authenticated user.
     *
     * @authenticated
     *
     *
     * @response 200 scenario="OK" {
     *   "success": true,
     *   "data": {
     *     "id": 1,
     *     "title": "Q1 Strategy Discussion",
     *     "created_at": "2026-02-10T20:00:00.000000Z",
     *     "updated_at": "2026-02-10T20:00:00.000000Z"
     *   },
     *   "message": "Success",
     *   "status": 200,
     *   "meta": {}
     * }
     * @response 401 scenario="Unauthenticated" {"message": "Unauthenticated."}
     */
    #[Endpoint(title: 'Create chat', description: 'Create a new AI chat session for the authenticated user.')]
    #[BodyParameter('title', 'Optional chat title.', required: false, type: 'string', example: 'Q1 Strategy Discussion')]
    #[BodyParameter('organization_id', 'Organization ID to bind the chat to.', required: true, type: 'integer', example: 1)]
    #[BodyParameter('team_id', 'Optional team ID to bind the chat to.', required: false, type: 'integer', example: 2)]
    #[Response(
        200,
        'Created chat envelope.',
        type: 'array{success: bool, data: \App\Http\Resources\API\v1\ChatResource, message: string, status: int, meta: array<string, mixed>}'
    )]
    public function store(ChatRequest $request): ApiResponse
    {
        $this->tenantScopeValidator->assertScopeIsValid(
            Auth::user(),
            $request->getOrganizationId(),
            $request->getTeamId(),
            false,
        );

        $chat = $this->chatService->create(
            Auth::user(),
            $request->getTitle(),
            $request->getOrganizationId(),
            $request->getTeamId(),
        );

        return ApiResponse::success(data: ChatResource::make($chat));
    }

    /**
     * Get chat
     *
     * Returns a single chat by ID. The authenticated user must own the chat.
     *
     * @authenticated
     *
     * @urlParam chat integer required The Chat ID. Example: 1
     *
     * @response 200 scenario="OK" {
     *   "success": true,
     *   "data": {
     *     "id": 1,
     *     "title": "Q1 Strategy Discussion",
     *     "created_at": "2026-02-10T20:00:00.000000Z",
     *     "updated_at": "2026-02-10T20:00:00.000000Z"
     *   },
     *   "message": "Success",
     *   "status": 200,
     *   "meta": {}
     * }
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [Chat] 1"}
     * @response 401 scenario="Unauthenticated" {"message": "Unauthenticated."}
     */
    #[Endpoint(title: 'Get chat', description: 'Return a single chat owned by the authenticated user.')]
    #[PathParameter('chat', 'Chat ID.', required: true, type: 'integer', example: 1)]
    #[Response(
        200,
        'Single chat envelope.',
        type: 'array{success: bool, data: \App\Http\Resources\API\v1\ChatResource, message: string, status: int, meta: array<string, mixed>}'
    )]
    public function show(ChatRequest $request, Chat $chat): ApiResponse
    {
        return ApiResponse::success(data: ChatResource::make($chat));
    }

    /**
     * Update chat
     *
     * Updates the title of an existing chat. The authenticated user must own the chat.
     *
     * @authenticated
     *
     * @urlParam chat integer required The Chat ID. Example: 1
     *
     * @response 200 scenario="OK" {
     *   "success": true,
     *   "data": {
     *     "id": 1,
     *     "title": "Updated Discussion Title",
     *     "created_at": "2026-02-10T20:00:00.000000Z",
     *     "updated_at": "2026-02-10T20:10:00.000000Z"
     *   },
     *   "message": "Success",
     *   "status": 200,
     *   "meta": {}
     * }
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [Chat] 1"}
     * @response 401 scenario="Unauthenticated" {"message": "Unauthenticated."}
     */
    #[Endpoint(title: 'Update chat', description: 'Update chat title for a chat owned by the authenticated user.')]
    #[PathParameter('chat', 'Chat ID.', required: true, type: 'integer', example: 1)]
    #[BodyParameter('title', 'Updated chat title.', required: false, type: 'string', example: 'Updated Discussion Title')]
    #[Response(
        200,
        'Updated chat envelope.',
        type: 'array{success: bool, data: \App\Http\Resources\API\v1\ChatResource, message: string, status: int, meta: array<string, mixed>}'
    )]
    public function update(ChatRequest $request, Chat $chat): ApiResponse
    {
        $this->tenantScopeValidator->assertScopeIsValid(
            Auth::user(),
            $request->has('organization_id') ? $request->getOrganizationId() : $chat->organization_id,
            $request->has('team_id') ? $request->getTeamId() : $chat->team_id,
        );

        $chat = $this->chatService->update(
            $chat,
            $request->getTitle(),
            $request->getOrganizationId(),
            $request->has('organization_id'),
            $request->getTeamId(),
            $request->has('team_id'),
        );

        return ApiResponse::success(data: ChatResource::make($chat));
    }

    /**
     * Delete chat
     *
     * Deletes a chat and all its messages. The authenticated user must own the chat.
     *
     * @authenticated
     *
     * @urlParam chat integer required The Chat ID. Example: 1
     *
     * @response 200 scenario="OK" {
     *   "success": true,
     *   "data": null,
     *   "message": "Success",
     *   "status": 200,
     *   "meta": {}
     * }
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [Chat] 1"}
     * @response 401 scenario="Unauthenticated" {"message": "Unauthenticated."}
     */
    #[Endpoint(title: 'Delete chat', description: 'Delete a chat and all its messages.')]
    #[PathParameter('chat', 'Chat ID.', required: true, type: 'integer', example: 1)]
    #[Response(
        200,
        'Chat deleted envelope.',
        type: 'array{success: bool, data: null, message: string, status: int, meta: array<string, mixed>}'
    )]
    public function destroy(ChatRequest $request, Chat $chat): ApiResponse
    {
        $this->chatService->delete($chat);

        return ApiResponse::success();
    }
}
