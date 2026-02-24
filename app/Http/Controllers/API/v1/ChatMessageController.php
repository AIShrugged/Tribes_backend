<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\ChatMessageRequest;
use App\Http\Resources\API\v1\ChatMessageResource;
use App\Http\Responses\ApiResponse;
use App\Models\Chat;
use App\Services\Chat\ChatMessageService;
use App\Services\Chat\ChatService;
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
        private readonly ChatService $chatService,
        private readonly ChatMessageService $messageService,
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
     *
     * @urlParam chat integer required The Chat ID. Example: 1
     *
     * @response 200 scenario="OK" {
     *   "success": true,
     *   "data": [
     *     {
     *       "id": 10,
     *       "chat_id": 1,
     *       "role": "user",
     *       "content": "What were the key decisions from yesterday's meeting?",
     *       "followup_data": null,
     *       "created_at": "2026-02-10T20:00:00.000000Z"
     *     },
     *     {
     *       "id": 11,
     *       "chat_id": 1,
     *       "role": "assistant",
     *       "content": "The key decisions were: 1) Launch by March 1st, 2) Hire two engineers.",
     *       "followup_data": null,
     *       "created_at": "2026-02-10T20:00:02.000000Z"
     *     }
     *   ],
     *   "message": "Success",
     *   "status": 200,
     *   "meta": {}
     * }
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [Chat] 1"}
     * @response 401 scenario="Unauthenticated" {"message": "Unauthenticated."}
     */
    public function index(ChatMessageRequest $request): ApiResponse
    {
        $chat = $this->chatService->findOrFail($request->getChatId());

        $this->authorize('view', $chat);

        $count = $this->messageService->countMessages($chat);
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
     *
     * @urlParam chat integer required The Chat ID. Example: 1
     *
     * @response 200 scenario="OK" {
     *   "success": true,
     *   "data": {
     *     "id": 12,
     *     "chat_id": 1,
     *     "role": "assistant",
     *     "content": "Last week's key points: 1) Q1 budget approved, 2) New hire process started.",
     *     "followup_data": null,
     *     "created_at": "2026-02-10T20:01:00.000000Z"
     *   },
     *   "message": "Success",
     *   "status": 200,
     *   "meta": {}
     * }
     * @response 422 scenario="Validation error" {
     *   "message": "The content field is required.",
     *   "errors": {"content": ["The content field is required."]}
     * }
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 404 scenario="Not Found" {"message": "No query results for model [Chat] 1"}
     * @response 401 scenario="Unauthenticated" {"message": "Unauthenticated."}
     */
    public function store(ChatMessageRequest $request): ApiResponse
    {
        $chat = $this->chatService->findOrFail($request->getChatId());

        $this->authorize('sendMessage', $chat);

        $response = $this->wandaBotService->processMessage(
            Auth::user(),
            $chat,
            $request->getMessageContent()
        );

        return ApiResponse::success(data: ChatMessageResource::make($response));
    }
}
