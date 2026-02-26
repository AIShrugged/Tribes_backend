<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\ChatMessageRequest;
use App\Http\Resources\API\v1\ChatMessageResource;
use App\Http\Responses\ApiResponse;
use App\Models\Conversation;
use App\Services\Chat\ConversationService;
use App\Services\Chat\MessageService;
use App\Services\Chat\WandaBotService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;

/**
 * @group Wanda Chat
 */
class ChatMessageController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly ConversationService $conversationService,
        private readonly MessageService $messageService,
        private readonly WandaBotService $wandaBotService
    ) {
    }

    /**
     * List messages
     *
     * Returns a paginated list of messages in a chat.
     * The total count is returned in the `Items-Count` response header.
     *
     * @subgroup Chat Messages
     * @authenticated
     * @urlParam chat integer required The Chat ID. Example: 1
     */
    public function index(ChatMessageRequest $request, Conversation $chat): ApiResponse
    {
        $this->authorize('view', $chat);

        $count    = $this->messageService->countMessages($chat);
        $messages = $this->messageService->getMessages(
            $chat,
            $request->getOffset(),
            $request->getLimit()
        );

        return ApiResponse::list(ChatMessageResource::collection($messages), $count);
    }

    /**
     * Send message
     *
     * Sends a user message to the Wanda AI bot and returns the bot's response.
     * Both the user message and the assistant reply are persisted in the chat history.
     *
     * @subgroup Chat Messages
     * @authenticated
     * @urlParam chat integer required The Chat ID. Example: 1
     */
    public function store(ChatMessageRequest $request, Conversation $chat): ApiResponse
    {
        $this->authorize('sendMessage', $chat);

        $response = $this->wandaBotService->processMessage(
            Auth::user(),
            $chat,
            $request->getMessageContent()
        );

        return ApiResponse::success(data: ChatMessageResource::make($response));
    }
}
