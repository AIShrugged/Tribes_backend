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

class ChatController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly ChatService $chatService
    ) {
        $this->authorizeResource(Chat::class, 'chat');
    }

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

    public function store(ChatRequest $request): ApiResponse
    {
        $chat = $this->chatService->create(
            Auth::user(),
            $request->getTitle()
        );

        return ApiResponse::success(data: ChatResource::make($chat));
    }

    public function show(ChatRequest $request, Chat $chat): ApiResponse
    {
        return ApiResponse::success(data: ChatResource::make($chat));
    }

    public function update(ChatRequest $request, Chat $chat): ApiResponse
    {
        $chat = $this->chatService->update($chat, $request->getTitle());

        return ApiResponse::success(data: ChatResource::make($chat));
    }

    public function destroy(ChatRequest $request, Chat $chat): ApiResponse
    {
        $this->chatService->delete($chat);

        return ApiResponse::success();
    }
}
