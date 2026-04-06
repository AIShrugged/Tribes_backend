<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ChannelConversation;
use App\Models\User;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

#[Group('Today Briefing')]
class TodayMessageController extends Controller
{
    #[Endpoint(title: 'Send direct message to user via Telegram', description: 'Sends a message to a specific user via their Telegram chat. No agent involved.')]
    public function send(Request $request): ApiResponse
    {
        $request->validate([
            'recipient_user_id' => ['required', 'integer', 'exists:users,id'],
            'message' => ['required', 'string', 'max:4000'],
        ]);

        $recipient = User::findOrFail($request->input('recipient_user_id'));
        $message = $request->input('message');

        // Find recipient's personal telegram chat
        $conversation = ChannelConversation::query()
            ->where('channel_type', 'telegram')
            ->where('user_id', $recipient->id)
            ->whereNotNull('telegram_chat_id')
            ->first();

        if (! $conversation || ! $conversation->telegram_chat_id) {
            return ApiResponse::error(
                message: 'Recipient has no Telegram chat connected',
                status: 422,
            );
        }

        try {
            $telegram = new Api(config('telegram.bot_token'));
            $telegram->sendMessage([
                'chat_id' => $conversation->telegram_chat_id,
                'text' => $message,
                'parse_mode' => 'HTML',
            ]);

            return ApiResponse::success(message: 'Message sent');
        } catch (\Throwable $e) {
            Log::warning('TodayMessageController: failed to send Telegram message', [
                'recipient_user_id' => $recipient->id,
                'telegram_chat_id' => $conversation->telegram_chat_id,
                'error' => $e->getMessage(),
            ]);

            return ApiResponse::error(
                message: 'Failed to send: ' . $e->getMessage(),
                status: 500,
            );
        }
    }
}
