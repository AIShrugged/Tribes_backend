<?php

namespace App\Http\Controllers\API\v1;

use App\Enums\ChannelType;
use App\Enums\OutputMode;
use App\Http\Controllers\Controller;
use App\Models\TelegramUser;
use App\Services\Agent\AgentService;
use App\Services\Agent\Tools\GetChatHistoryTool;
use App\Services\Agent\Tools\ToolRegistry;
use App\Services\Chat\ConversationService;
use App\Services\Chat\MessageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramSDKException;

/**
 * @hideFromAPIDocumentation
 */
class TelegramBotController extends Controller
{
    public function __construct(
        private readonly Api $telegram,
        private readonly AgentService $agentService,
        private readonly ToolRegistry $toolRegistry,
        private readonly ConversationService $conversationService,
        private readonly MessageService $messageService,
    ) {
    }

    /**
     * Handle incoming webhook from Telegram
     */
    public function webhook(Request $request)
    {
        try {
            $update = $this->telegram->getWebhookUpdate();

            if ($update->isType('message') && ($message = $update->getMessage())) {
                $chatId          = $message->getChat()->getId();
                $chatType        = $message->getChat()->getType();
                $text            = $message->getText();
                $telegramUserId  = $message->getFrom()?->getId();
                $username        = $message->getFrom()?->getUsername();
                $messageThreadId = $message->get('message_thread_id');

                // Skip system messages (new_chat_member, left_chat_member, etc.) without text
                if ($text === null || trim($text) === '') {
                    Log::info('Telegram system event received (no text)', [
                        'chat_id'           => $chatId,
                        'chat_type'         => $chatType,
                        'new_chat_member'   => $message->getNewChatMember()?->getUsername(),
                        'left_chat_member'  => $message->getLeftChatMember()?->getUsername(),
                    ]);

                    return response()->json(['ok' => true]);
                }

                // Skip messages without user info
                if (! $telegramUserId) {
                    Log::info('Telegram message without user info', [
                        'chat_id' => $chatId,
                        'text'    => $text,
                    ]);

                    return response()->json(['ok' => true]);
                }

                // Find or create telegram user and resolve application user
                $telegramUser  = TelegramUser::findOrCreateByTelegramId($telegramUserId, $username);
                $user          = $telegramUser->user;
                $isPrivateChat = $chatType === 'private';
                $channelType   = $isPrivateChat ? ChannelType::TelegramPrivate : ChannelType::TelegramGroup;

                // Find or create conversation for this Telegram chat
                $conversation = $this->conversationService->findOrCreateTelegramConversation(
                    $telegramUser,
                    $chatId,
                    $channelType
                );

                // Save incoming user message (save ALL messages, not just mentions)
                $this->messageService->createUserMessage($conversation, $telegramUser, $text);

                // Only respond when bot is mentioned (except in private chats)
                $botUsername = config('telegram.bot_username');
                if (! $isPrivateChat && (! $botUsername || ! $this->isBotMentioned($text, $botUsername))) {
                    return response()->json(['ok' => true]);
                }

                // Check whitelist (empty = allow all)
                if (! $this->isUserAllowed($telegramUserId)) {
                    Log::info('Unauthorized user message (ignored)', [
                        'chat_id'  => $chatId,
                        'user_id'  => $telegramUserId,
                        'username' => $username,
                        'text'     => $text,
                    ]);

                    return response()->json(['ok' => true]);
                }

                // Remove mention if present (in group chats)
                if (! $isPrivateChat && $botUsername) {
                    $text = trim($this->removeMention($text, $botUsername));
                }

                if (empty(trim($text))) {
                    return response()->json(['ok' => true]);
                }

                Log::info('Telegram message received', [
                    'chat_id'   => $chatId,
                    'chat_type' => $chatType,
                    'user_id'   => $telegramUserId,
                    'username'  => $username,
                    'text'      => $text,
                ]);

                // Handle /stop command
                if ($text === '/stop') {
                    if ($user) {
                        $this->agentService->requestStop($user->id);
                    }
                    $stopMessage = '⛔️ Stop signal sent. Current processing will be interrupted.';
                    $sendParams  = ['chat_id' => $chatId, 'text' => $stopMessage];
                    if ($messageThreadId) {
                        $sendParams['message_thread_id'] = $messageThreadId;
                    }
                    $this->telegram->sendMessage($sendParams);

                    $this->messageService->createAssistantMessage($conversation, $stopMessage);

                    return response()->json(['ok' => true]);
                }

                // Cannot process without a linked application user
                if (! $user) {
                    Log::warning('TelegramUser has no linked User account — cannot process message', [
                        'telegram_user_id' => $telegramUserId,
                    ]);

                    return response()->json(['ok' => true]);
                }

                // Register Telegram-specific tool for this conversation's history
                $this->toolRegistry->register(new GetChatHistoryTool($conversation));

                // Process message through channel-agnostic agent
                $response = $this->agentService->processMessage($user, collect(), $text, 'telegram', OutputMode::MD);

                // Send response back to chat
                $sendParams = ['chat_id' => $chatId, 'text' => $response, 'parse_mode' => 'Markdown'];
                if ($messageThreadId) {
                    $sendParams['message_thread_id'] = $messageThreadId;
                }
                $this->telegram->sendMessage($sendParams);

                // Save bot response
                $this->messageService->createAssistantMessage($conversation, $response);
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
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
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
        return str_contains(mb_strtolower($text), '@' . mb_strtolower($botUsername));
    }

    private function removeMention(string $text, string $botUsername): string
    {
        return preg_replace('/@' . preg_quote($botUsername, '/') . '/i', '', $text);
    }
}
