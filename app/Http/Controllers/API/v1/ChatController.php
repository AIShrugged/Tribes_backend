<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\ChatRequest;
use App\Http\Resources\API\v1\ChatResource;
use App\Http\Responses\ApiResponse;
use App\Models\Chat;
use App\Services\Chat\ChatService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;

/**
 * @group Wanda Chat
 */
class ChatController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly ChatService $chatService
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
    public function index(ChatRequest $request): ApiResponse
    {
        $user = Auth::user();
        $count = $this->chatService->countChatsForUser($user);
        $chats = $this->chatService->getChatsForUser(
            $user,
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
    public function store(ChatRequest $request): ApiResponse
    {
        $chat = $this->chatService->create(
            Auth::user(),
            $request->getTitle()
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
    public function update(ChatRequest $request, Chat $chat): ApiResponse
    {
        $chat = $this->chatService->update($chat, $request->getTitle());

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
    public function destroy(ChatRequest $request, Chat $chat): ApiResponse
    {
        $this->chatService->delete($chat);

        return ApiResponse::success();
    }
}
