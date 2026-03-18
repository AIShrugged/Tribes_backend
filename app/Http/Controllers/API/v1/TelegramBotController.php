<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Models\TelegramUser;
use App\Services\Agent\AgentService;
use App\Services\Channel\ChannelRuntimeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramSDKException;

/**
 * @hideFromAPIDocumentation
 */
class TelegramBotController extends Controller
{
    private Api $telegram;

    private AgentService $agentService;

    public function __construct(AgentService $agentService, private readonly ChannelRuntimeService $runtimeService)
    {
        $this->telegram = new Api(config('telegram.bot_token'));
        $this->agentService = $agentService;
    }

    /**
     * Handle incoming webhook from Telegram
     */
    public function webhook(Request $request)
    {
        try {
            $update = $this->telegram->getWebhookUpdate();

            if ($update->isType('message') && ($message = $update->getMessage())) {
                $chatId = $message->getChat()->getId();
                $chatType = $message->getChat()->getType();
                $text = $message->getText();
                $telegramUserId = $message->getFrom()?->getId();
                $username = $message->getFrom()?->getUsername();
                $messageThreadId = $message->get('message_thread_id');

                // Skip system messages (new_chat_member, left_chat_member, etc.) without text
                if ($text === null || trim($text) === '') {
                    Log::info('Telegram system event received (no text)', [
                        'chat_id' => $chatId,
                        'chat_type' => $chatType,
                        'new_chat_member' => $message->getNewChatMember()?->getUsername(),
                        'left_chat_member' => $message->getLeftChatMember()?->getUsername(),
                    ]);

                    return response()->json(['ok' => true]);
                }

                // Skip messages without user info
                if (! $telegramUserId) {
                    Log::info('Telegram message without user info', [
                        'chat_id' => $chatId,
                        'text' => $text,
                    ]);

                    return response()->json(['ok' => true]);
                }

                // Find or create telegram user and resolve application user
                $telegramUser = TelegramUser::findOrCreateByTelegramId($telegramUserId, $username);
                $user = $telegramUser->user;

                // Save incoming user message (save ALL messages, not just mentions)
                $incomingMessage = $this->runtimeService->recordTelegramInbound(
                    $telegramUser,
                    $chatId,
                    $messageThreadId,
                    $text,
                );

                // Only respond when bot is mentioned (except in private chats)
                $botUsername = config('telegram.bot_username');
                $isPrivateChat = $chatType === 'private';
                if (! $isPrivateChat && (! $botUsername || ! $this->isBotMentioned($text, $botUsername))) {
                    Log::debug('Telegram group message ignored because bot was not mentioned', [
                        'chat_id' => $chatId,
                        'user_id' => $telegramUserId,
                        'username' => $username,
                    ]);

                    return response()->json(['ok' => true]);
                }

                // Check whitelist (empty = allow all)
                if (! $this->isUserAllowed($telegramUserId)) {
                    Log::info('Unauthorized user message (ignored)', [
                        'chat_id' => $chatId,
                        'user_id' => $telegramUserId,
                        'username' => $username,
                        'text' => $text,
                    ]);

                    $this->sendTelegramMessage(
                        $chatId,
                        'Access denied for this Telegram account.',
                        $messageThreadId,
                    );

                    return response()->json(['ok' => true]);
                }

                // Remove mention if present (in group chats)
                if (! $isPrivateChat && $botUsername) {
                    $text = trim($this->removeMention($text, $botUsername));
                }

                if (empty(trim($text))) {
                    return response()->json(['ok' => true]);
                }

                if ($incomingMessage->content !== $text) {
                    $incomingMessage->update(['content' => $text]);
                }

                Log::info('Telegram message received', [
                    'chat_id' => $chatId,
                    'chat_type' => $chatType,
                    'user_id' => $telegramUserId,
                    'username' => $username,
                    'text' => $text,
                ]);

                // Handle /stop command
                if ($text === '/stop') {
                    if ($user) {
                        $this->agentService->requestStop($user->id);
                    }
                    $stopMessage = '⛔️ Stop signal sent. Current processing will be interrupted.';
                    $sendParams = ['chat_id' => $chatId, 'text' => $stopMessage];
                    if ($messageThreadId) {
                        $sendParams['message_thread_id'] = $messageThreadId;
                    }
                    $this->telegram->sendMessage($sendParams);

                    $this->runtimeService->deliverToConversation(
                        $incomingMessage->conversation()->firstOrFail(),
                        $stopMessage,
                    );

                    return response()->json(['ok' => true]);
                }

                // Cannot process without a linked application user
                if (! $user) {
                    Log::warning('TelegramUser has no linked User account — cannot process message', [
                        'telegram_user_id' => $telegramUserId,
                    ]);

                    $this->sendTelegramMessage(
                        $chatId,
                        'Your Telegram account is not linked to an application user yet.',
                        $messageThreadId,
                    );

                    return response()->json(['ok' => true]);
                }

                $this->runtimeService->scheduleTelegramBranch($chatId, $messageThreadId);
            }

            return response()->json(['ok' => true]);
        } catch (TelegramSDKException $e) {
            Log::error('Telegram webhook error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Always return 200 OK to Telegram to avoid retries
            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            Log::error('Telegram webhook unexpected error', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Always return 200 OK to Telegram to avoid retries
            return response()->json(['ok' => true]);
        }
    }

    private function isUserAllowed(int $userId): bool
    {
        $allowedUsers = config('telegram.allowed_users', '');
        if (empty($allowedUsers)) {
            return true;
        }

        $allowedIds = array_map('trim', explode(',', $allowedUsers));

        return in_array((string) $userId, $allowedIds);
    }

    private function isBotMentioned(string $text, string $botUsername): bool
    {
        return str_contains(mb_strtolower($text), '@'.mb_strtolower($botUsername));
    }

    private function removeMention(string $text, string $botUsername): string
    {
        return preg_replace('/@'.preg_quote($botUsername, '/').'/i', '', $text);
    }

    private function sendTelegramMessage(int $chatId, string $text, ?int $messageThreadId = null): void
    {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
        ];

        if ($messageThreadId) {
            $params['message_thread_id'] = $messageThreadId;
        }

        $this->telegram->sendMessage($params);
    }
}
