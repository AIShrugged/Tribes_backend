<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Services\Agent\AgentService;
use App\Services\Agent\MemoryService;
use App\Services\Agent\Tools\GetTranscriptTool;
use App\Services\Agent\Tools\GetUserInfoTool;
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
        $toolRegistry = new ToolRegistry();
        $toolRegistry->register(new GetUserInfoTool());
        $toolRegistry->register(new GetTranscriptTool());
        $toolRegistry->register(new SearchMeetingsTool());

        $memoryService = new MemoryService();

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
                $userId = $message->getFrom()->getId();
                $username = $message->getFrom()->getUsername();
                $isGroup = in_array($chatType, ['group', 'supergroup']);

                // Check whitelist (empty = allow all)
                if (!$this->isUserAllowed($userId)) {
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => "В доступе отказано. Данный функционал ориентирован на пользователей с иным уровнем статуса и назначения, нежели тот, к которому вы относитесь.",
                    ]);
                    Log::info('Unauthorized user message', [
                        'chat_id' => $chatId,
                        'user_id' => $userId,
                        'username' => $username,
                        'text' => $text,
                    ]);
                    return response()->json(['ok' => true]);
                }

                // In group chats, only respond when bot is mentioned
                if ($isGroup) {
                    $botUsername = config('telegram.bot_username');
                    if (!$botUsername || !$this->isBotMentioned($text, $botUsername)) {
                        return response()->json(['ok' => true]);
                    }
                    $text = trim($this->removeMention($text, $botUsername));
                    if (empty($text)) {
                        return response()->json(['ok' => true]);
                    }
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
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => "⛔️ Stop signal sent. Current processing will be interrupted.",
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
            }

            return response()->json(['ok' => true]);
        } catch (TelegramSDKException $e) {
            Log::error('Telegram webhook error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['error' => $e->getMessage()], 500);
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