<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\ChatMessageRequest;
use App\Http\Resources\API\v1\ChatMessageResource;
use App\Http\Resources\API\v1\ChatRunStatusResource;
use App\Http\Responses\ApiResponse;
use App\Models\Chat;
use App\Services\Chat\ChatMessageService;
use App\Services\Chat\ChatService;
use App\Services\Chat\WandaBotService;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;

#[Group('Wanda Chat', 'Chat messages and async run status for Wanda conversations.')]
class ChatMessageController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly ChatService $chatService,
        private readonly ChatMessageService $messageService,
        private readonly WandaBotService $wandaBotService,
    ) {}

    /**
     * List messages
     *
     * Returns a paginated list of messages in a chat.
     * The total count is returned in the `Items-Count` response header.
     *
     * @subgroup Chat Messages
     *
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
    #[Endpoint(title: 'List chat messages', description: 'Return paginated messages for a given chat.')]
    #[PathParameter('chat', 'Chat ID.', required: true, type: 'integer', example: 1)]
    #[Response(
        200,
        'Chat message list envelope.',
        type: 'array{success: bool, data: array<int, \App\Http\Resources\API\v1\ChatMessageResource>, message: string, status: int, meta: array<string, mixed>}'
    )]
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
     * Get run status
     *
     * Polls the status of an asynchronous assistant run created by sending a message.
     * Use the `agent_run_uuid` returned from `POST /chats/{chat}/messages`.
     *
     * @subgroup Chat Messages
     *
     * @authenticated
     *
     * @urlParam chat integer required The Chat ID. Example: 1
     * @urlParam runUuid string required The agent run UUID returned when the queued assistant message was created.
     *
     * @response 200 scenario="Queued" {"success":true,"data":{"agent_run_uuid":"3f7d1a53-4d74-4f59-9e75-1f3d4e5e2c11","chat_id":1,"message_id":12,"status":"queued","progress_percent":5,"current_step_label":"Queued","error_message":null,"failure_code":null,"current_attempt":0,"max_attempts":3,"completed_at":null,"next_retry_at":null,"message":{"id":12,"role":"assistant","content":"Processing...","created_at":"2026-03-16T10:00:00.000000Z"}},"message":"Success","status":200,"meta":[]}
     * @response 200 scenario="Retrying" {"success":true,"data":{"agent_run_uuid":"3f7d1a53-4d74-4f59-9e75-1f3d4e5e2c11","chat_id":1,"message_id":12,"status":"retrying","progress_percent":25,"current_step_label":"Retrying after failure","error_message":"Temporary upstream error","failure_code":"AI_REQUEST_FAILED","current_attempt":1,"max_attempts":3,"completed_at":null,"next_retry_at":"2026-03-16T10:00:10.000000Z","message":{"id":12,"role":"assistant","content":"Processing...","created_at":"2026-03-16T10:00:00.000000Z"}},"message":"Success","status":200,"meta":[]}
     * @response 200 scenario="Completed" {"success":true,"data":{"agent_run_uuid":"3f7d1a53-4d74-4f59-9e75-1f3d4e5e2c11","chat_id":1,"message_id":12,"status":"completed","progress_percent":100,"current_step_label":"Completed","error_message":null,"failure_code":null,"current_attempt":1,"max_attempts":3,"completed_at":"2026-03-16T10:00:05.000000Z","next_retry_at":null,"message":{"id":12,"role":"assistant","content":"Final answer","created_at":"2026-03-16T10:00:00.000000Z"}},"message":"Success","status":200,"meta":[]}
     * @response 404 scenario="Not Found" {"success":false,"data":null,"message":"Run not found","status":404,"meta":[]}
     */
    #[Endpoint(title: 'Get chat run status', description: 'Poll the status of an asynchronous assistant run created after sending a message.')]
    #[PathParameter('chat', 'Chat ID.', required: true, type: 'integer', example: 1)]
    #[PathParameter('runUuid', 'Agent run UUID.', required: true, type: 'string', example: '3f7d1a53-4d74-4f59-9e75-1f3d4e5e2c11')]
    #[Response(
        200,
        'Run status envelope.',
        type: 'array{success: bool, data: \App\Http\Resources\API\v1\ChatRunStatusResource, message: string, status: int, meta: array<string, mixed>}'
    )]
    #[Response(
        404,
        'Run not found.',
        type: 'array{success: bool, data: null, message: string, status: int, meta: array<string, mixed>}'
    )]
    public function showRunStatus(Chat $chat, string $runUuid): ApiResponse
    {
        $this->authorize('view', $chat);

        $runMessage = $this->messageService->findAssistantRun($chat, $runUuid);

        if (! $runMessage) {
            return ApiResponse::error('Run not found', status: 404);
        }

        return ApiResponse::success(data: ChatRunStatusResource::make($runMessage));
    }

    /**
     * Send message
     *
     * Sends a user message to the Wanda AI bot and returns a queued assistant message.
     * The final assistant reply is produced asynchronously and becomes available via message polling.
     *
     * @subgroup Chat Messages
     *
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
     *     "status": "queued",
     *     "content": "Processing...",
     *     "followup_data": null,
     *     "error_message": null,
     *     "failure_code": null,
     *     "agent_run_uuid": "3f7d1a53-4d74-4f59-9e75-1f3d4e5e2c11",
     *     "current_attempt": 0,
     *     "max_attempts": 3,
     *     "completed_at": null,
     *     "next_retry_at": null,
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
    #[Endpoint(title: 'Send chat message', description: 'Send a user message to Wanda and return the queued assistant message placeholder.')]
    #[PathParameter('chat', 'Chat ID.', required: true, type: 'integer', example: 1)]
    #[BodyParameter('content', 'Message text to send to the bot.', required: true, type: 'string', example: 'Summarise the key points from last week\'s meetings.')]
    #[Response(
        200,
        'Queued assistant message envelope.',
        type: 'array{success: bool, data: \App\Http\Resources\API\v1\ChatMessageResource, message: string, status: int, meta: array<string, mixed>}'
    )]
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
