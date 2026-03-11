<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\ChatRequest;
use App\Http\Resources\API\v1\ChatResource;
use App\Http\Responses\ApiResponse;
use App\Models\Conversation;
use App\Services\Chat\ConversationService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;

/**
 * @group Wanda Chat
 */
class ChatController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly ConversationService $conversationService
    ) {
        $this->authorizeResource(Conversation::class, 'chat');
    }

    /**
     * List chats
     *
     * Returns a paginated list of AI chats belonging to the authenticated user.
     * The total count is returned in the `Items-Count` response header.
     *
     * @authenticated
     */
    public function index(ChatRequest $request): ApiResponse
    {
        $user  = Auth::user();
        $count = $this->conversationService->countConversationsForUser($user);
        $chats = $this->conversationService->getConversationsForUser(
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
     */
    public function store(ChatRequest $request): ApiResponse
    {
        $conversation = $this->conversationService->createWebConversation(
            Auth::user(),
            $request->getTitle()
        );

        return ApiResponse::success(data: ChatResource::make($conversation));
    }

    /**
     * Get chat
     *
     * Returns a single chat by ID. The authenticated user must be a participant.
     *
     * @authenticated
     * @urlParam chat integer required The Chat ID. Example: 1
     */
    public function show(ChatRequest $request, Conversation $chat): ApiResponse
    {
        return ApiResponse::success(data: ChatResource::make($chat));
    }

    /**
     * Update chat
     *
     * Updates the title of an existing chat. The authenticated user must be the owner.
     *
     * @authenticated
     * @urlParam chat integer required The Chat ID. Example: 1
     */
    public function update(ChatRequest $request, Conversation $chat): ApiResponse
    {
        $chat = $this->conversationService->update($chat, $request->getTitle());

        return ApiResponse::success(data: ChatResource::make($chat));
    }

    /**
     * Delete chat
     *
     * Deletes a chat and all its messages. The authenticated user must be the owner.
     *
     * @authenticated
     * @urlParam chat integer required The Chat ID. Example: 1
     */
    public function destroy(ChatRequest $request, Conversation $chat): ApiResponse
    {
        $this->conversationService->delete($chat);

        return ApiResponse::success();
    }
}
