<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Models\TelegramUser;
use App\Services\Agent\AgentService;
use App\Services\Channel\ChannelBus;
use App\Services\Channel\ChannelRuntimeService;
use App\Services\Channel\TelegramTypingIndicator;
use App\Services\Issue\ValidationReplyHandler;
use App\Services\TelegramChatRegistrationService;
use App\Services\TelegramLinkService;
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

    public function __construct(
        AgentService $agentService,
        private readonly ChannelBus $channelBus,
        private readonly ChannelRuntimeService $runtimeService,
        private readonly TelegramTypingIndicator $typingIndicator,
        private readonly TelegramChatRegistrationService $telegramChatRegistrationService,
        private readonly TelegramLinkService $telegramLinkService,
        private readonly ValidationReplyHandler $validationReplyHandler,
    ) {
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

            if ($update->isType('my_chat_member') && ($chatMemberUpdate = $update->get('my_chat_member'))) {
                $chat = $chatMemberUpdate->chat;
                $chatId = $chat?->id;
                $chatType = $chat?->type;
                $chatTitle = $chat?->title;
                $oldStatus = $chatMemberUpdate->oldChatMember?->status;
                $newStatus = $chatMemberUpdate->newChatMember?->status;

                Log::info('Telegram my_chat_member update received', [
                    'chat_id' => $chatId,
                    'chat_type' => $chatType,
                    'chat_title' => $chatTitle,
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                ]);

                if (in_array($chatType, ['group', 'supergroup'], true)) {
                    if ($this->isBotMembershipActive($newStatus)) {
                        $conversation = $this->channelBus->forTelegram($chatId);
                        $this->telegramChatRegistrationService->discoverGroupConversation($conversation, $chatType, $chatTitle);
                    }

                    if ($this->isBotMembershipInactive($newStatus)) {
                        Log::info('Telegram bot removed from group chat', [
                            'chat_id' => $chatId,
                            'chat_type' => $chatType,
                            'chat_title' => $chatTitle,
                        ]);
                        $this->telegramChatRegistrationService->unbindGroupConversation($chatId);
                    }
                }

                return response()->json(['ok' => true]);
            }

            if ($update->isType('message') && ($message = $update->getMessage())) {
                $chatId = $message->getChat()->getId();
                $chatType = $message->getChat()->getType();
                $chatTitle = $message->getChat()->getTitle();
                $text = $message->getText();
                $telegramUserId = $message->getFrom()?->getId();
                $username = $message->getFrom()?->getUsername();
                $messageThreadId = $message->get('message_thread_id');

                if ($this->isBotAddedEvent($message)) {
                    $conversation = $this->channelBus->forTelegram($chatId, $messageThreadId);
                    $this->telegramChatRegistrationService->discoverGroupConversation($conversation, $chatType, $chatTitle);

                    return response()->json(['ok' => true]);
                }

                // Skip other system messages (left_chat_member, etc.) without text
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

                if ($this->isLinkCommand($text)) {
                    $token = $this->extractLinkToken($text);
                    try {
                        $this->telegramLinkService->consumeToken($token, $telegramUserId, $username);
                        $this->sendTelegramMessage(
                            $chatId,
                            'Ваш Telegram аккаунт успешно привязан к приложению!',
                            $messageThreadId,
                        );
                    } catch (\RuntimeException $e) {
                        $this->sendTelegramMessage(
                            $chatId,
                            'Не удалось привязать аккаунт: '.$e->getMessage(),
                            $messageThreadId,
                        );
                    }

                    return response()->json(['ok' => true]);
                }

                $conversation = $this->channelBus->forTelegram($chatId, $messageThreadId);

                if (in_array($chatType, ['group', 'supergroup'], true)) {
                    $registration = $this->telegramChatRegistrationService->discoverGroupConversation($conversation, $chatType, $chatTitle);

                    if ($registration->bound_at === null) {
                        return response()->json(['ok' => true]);
                    }
                }

                if ($chatType === 'private' && $user) {
                    $this->telegramChatRegistrationService->bindPrivateConversation($conversation, $user);
                }

                // Save incoming user message (save ALL messages, not just mentions)
                $incomingMessage = $this->runtimeService->recordTelegramInbound(
                    $telegramUser,
                    $chatId,
                    $messageThreadId,
                    $text,
                );

                // Reply to a pending IssueAgentFlow validation question (private chats only).
                // Deterministic path: match by (chat_id, reply_to_message_id, user_id).
                $isPrivateChat = $chatType === 'private';
                $replyToMessageId = $message->getReplyToMessage()?->getMessageId();
                if ($isPrivateChat && $user && $replyToMessageId) {
                    if ($this->handleValidationReply($chatId, (int) $replyToMessageId, $user->id, $text, $messageThreadId)) {
                        return response()->json(['ok' => true]);
                    }
                }

                // Only respond when bot is mentioned (except in private chats)
                $botUsername = config('telegram.bot_username');
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

                $conversation = $incomingMessage->conversation()->firstOrFail();

                if ($conversation->organization_id === null) {
                    if ($chatType === 'private') {
                        $typingSessionId = $this->typingIndicator->sessionId($chatId, $messageThreadId);
                        $this->typingIndicator->start($typingSessionId, $chatId, $messageThreadId);

                        $this->runtimeService->scheduleTelegramBranch($chatId, $messageThreadId);

                        return response()->json(['ok' => true]);
                    }

                    Log::info('Telegram conversation is not bound to an organization yet', [
                        'conversation_id' => $conversation->id,
                        'chat_id' => $chatId,
                        'message_thread_id' => $messageThreadId,
                    ]);

                    $this->sendTelegramMessage(
                        $chatId,
                        'This chat is not linked to an organization yet. Bind it in the backend before using the bot.',
                        $messageThreadId,
                    );

                    return response()->json(['ok' => true]);
                }

                $typingSessionId = $this->typingIndicator->sessionId($chatId, $messageThreadId);
                $this->typingIndicator->start($typingSessionId, $chatId, $messageThreadId);

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

    private function isBotAddedEvent($message): bool
    {
        $botUsername = config('telegram.bot_username');
        $newChatMember = $message->getNewChatMember();

        if (! $newChatMember || ! $botUsername) {
            return false;
        }

        $username = $newChatMember->getUser()?->getUsername();

        return mb_strtolower((string) $username) === mb_strtolower($botUsername);
    }

    private function removeMention(string $text, string $botUsername): string
    {
        return preg_replace('/@'.preg_quote($botUsername, '/').'/i', '', $text);
    }

    private function isBotMembershipActive(?string $status): bool
    {
        return in_array($status, ['member', 'administrator'], true);
    }

    private function isBotMembershipInactive(?string $status): bool
    {
        return in_array($status, ['left', 'kicked'], true);
    }

    private function isLinkCommand(string $text): bool
    {
        return preg_match('/^\/start\s+[a-zA-Z0-9]{32}$/', trim($text)) === 1;
    }

    private function extractLinkToken(string $text): string
    {
        preg_match('/^\/start\s+([a-zA-Z0-9]{32})$/', trim($text), $matches);

        return $matches[1] ?? '';
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

    /**
     * Try to consume an IssueAgentFlow validation reply. Returns true when the
     * incoming Telegram message replies to a tracked pending question and was
     * handled (either fed into the flow or rejected with a notice).
     */
    private function handleValidationReply(
        int $chatId,
        int $replyToMessageId,
        int $userId,
        string $text,
        ?int $messageThreadId,
    ): bool {
        $outcome = $this->validationReplyHandler->handleTelegramReply(
            $chatId,
            $replyToMessageId,
            $userId,
            $text,
        );

        if ($outcome === null) {
            return false;
        }

        $this->sendTelegramMessage($chatId, $outcome->message, $messageThreadId);

        return true;
    }
}
