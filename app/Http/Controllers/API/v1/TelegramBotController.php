<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Models\TelegramChatMessage;
use App\Models\TelegramUser;
use App\Services\Agent\AgentService;
use App\Services\Agent\MemoryService;
use App\Services\Agent\Tools\GetRelationshipInsightTool;
use App\Services\Agent\Tools\GetTeamMembersTool;
use App\Services\Agent\Tools\GetTranscriptTool;
use App\Services\Agent\Tools\GetUserInfoTool;
use App\Services\Agent\Tools\GetUserInsightsTool;
use App\Services\Agent\Tools\GetUserShortTermMemoryTool;
use App\Services\Agent\Tools\SearchMeetingsTool;
use App\Services\Agent\Tools\ToolRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramSDKException;

class TelegramBotController extends Controller
{
    private Api $telegram;

    private AgentService $agentService;

    public function __construct()
    {
        $this->telegram = new Api(config('telegram.bot_token'));
        $this->agentService = $this->initializeAgentService();
    }

    private function initializeAgentService(): AgentService
    {
        $toolRegistry = new ToolRegistry;
        $toolRegistry->register(new GetUserInfoTool);
        $toolRegistry->register(new GetTranscriptTool);
        $toolRegistry->register(new SearchMeetingsTool);
        $toolRegistry->register(new GetTeamMembersTool);
        $toolRegistry->register(new GetUserInsightsTool);
        $toolRegistry->register(new GetUserShortTermMemoryTool);
        $toolRegistry->register(new GetRelationshipInsightTool);

        $memoryService = new MemoryService;

        return new AgentService($toolRegistry, $memoryService);
    }

    /**
     * Handle incoming webhook from Telegram
     */
    public function webhook(Request $request)
    {
        try {
            $update = $this->telegram->getWebhookUpdate();

            if ($message = $update->getMessage()) {
                $chatId = $message->getChat()->getId();
                $chatType = $message->getChat()->getType();
                $text = $message->getText();
                $userId = $message->getFrom()?->getId();
                $username = $message->getFrom()?->getUsername();

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
                if (! $userId) {
                    Log::info('Telegram message without user info', [
                        'chat_id' => $chatId,
                        'text' => $text,
                    ]);

                    return response()->json(['ok' => true]);
                }

                // Find or create telegram user and save ALL messages (before mention check)
                $telegramUser = TelegramUser::findOrCreateByTelegramId($userId, $username);

                // Save incoming user message (save ALL messages, not just mentions)
                TelegramChatMessage::create([
                    'telegram_chat_id' => $chatId,
                    'telegram_user_id' => $telegramUser->telegram_user_id,
                    'role' => 'user',
                    'content' => $text,
                ]);

                // Only respond when bot is mentioned (except in private chats)
                $botUsername = config('telegram.bot_username');
                $isPrivateChat = $chatType === 'private';
                if (! $isPrivateChat && (! $botUsername || ! $this->isBotMentioned($text, $botUsername))) {
                    return response()->json(['ok' => true]);
                }

                // Check whitelist (empty = allow all)
                if (! $this->isUserAllowed($userId)) {
                    Log::info('Unauthorized user message (ignored)', [
                        'chat_id' => $chatId,
                        'user_id' => $userId,
                        'username' => $username,
                        'text' => $text,
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
                    'chat_id' => $chatId,
                    'chat_type' => $chatType,
                    'user_id' => $userId,
                    'username' => $username,
                    'text' => $text,
                ]);

                // Handle /stop command
                if ($text === '/stop') {
                    $this->agentService->requestStop($userId);
                    $stopMessage = '⛔️ Stop signal sent. Current processing will be interrupted.';
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => $stopMessage,
                    ]);

                    // Save bot response
                    TelegramChatMessage::create([
                        'telegram_chat_id' => $chatId,
                        'telegram_user_id' => $telegramUser->telegram_user_id,
                        'role' => 'assistant',
                        'content' => $stopMessage,
                    ]);

                    return response()->json(['ok' => true]);
                }

                // Process message through agent
                $response = $this->agentService->processMessage($userId, $text, $username, $chatId);

                // Send response back to chat
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => $response,
                ]);

                // Save bot response
                TelegramChatMessage::create([
                    'telegram_chat_id' => $chatId,
                    'telegram_user_id' => $telegramUser->telegram_user_id,
                    'role' => 'assistant',
                    'content' => $response,
                ]);
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
}
