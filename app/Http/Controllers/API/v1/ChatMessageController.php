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

class ChatMessageController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly ChatService $chatService,
        private readonly ChatMessageService $messageService,
        private readonly WandaBotService $wandaBotService
    ) {
    }

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
