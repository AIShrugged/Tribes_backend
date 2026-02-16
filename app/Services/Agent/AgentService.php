<?php

namespace App\Services\Agent;

use App\Models\TelegramChatMessage;
use App\Models\TelegramUser;
use App\Services\Agent\Tools\ToolRegistry;
use App\Services\OpenRouterClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AgentService
{
    private const MAX_ITERATIONS = 10;

    private const MODEL = 'anthropic/claude-3.5-sonnet';

    private const STOP_KEY_PREFIX = 'telegram_agent_stop_';

    /** Maximum characters for a single tool result before truncation */
    private const MAX_TOOL_RESULT_CHARS = 15000;

    /** Approximate model context window in tokens (Claude 3.5 Sonnet = 200K) */
    private const MODEL_CONTEXT_LIMIT = 200000;

    /** Start masking aggressively when context exceeds this fraction of limit */
    private const TOKEN_BUDGET_WARNING_THRESHOLD = 0.8;

    /** Force-stop the loop when context exceeds this fraction of limit */
    private const TOKEN_BUDGET_CRITICAL_THRESHOLD = 0.9;

    private ToolRegistry $toolRegistry;

    private MemoryService $memoryService;

    public function __construct(
        ToolRegistry $toolRegistry,
        MemoryService $memoryService
    ) {
        $this->toolRegistry = $toolRegistry;
        $this->memoryService = $memoryService;
    }

    /**
     * Request stop for a telegram user's current processing
     */
    public function requestStop(int $telegramUserId): void
    {
        Cache::put(self::STOP_KEY_PREFIX.$telegramUserId, true, 60);
        Log::info('Stop requested for telegram user', ['telegram_user_id' => $telegramUserId]);
    }

    /**
     * Check if stop was requested for this user
     */
    private function isStopRequested(int $telegramUserId): bool
    {
        return Cache::get(self::STOP_KEY_PREFIX.$telegramUserId, false);
    }

    /**
     * Clear stop flag for user
     */
    private function clearStopFlag(int $telegramUserId): void
    {
        Cache::forget(self::STOP_KEY_PREFIX.$telegramUserId);
    }

    /**
     * Process a message from user through the agent loop
     */
    public function processMessage(int $telegramUserId, string $userMessage, ?string $username = null, ?int $telegramChatId = null): string
    {
        // Clear any previous stop flags
        $this->clearStopFlag($telegramUserId);

        // Find or create telegram user
        $telegramUser = TelegramUser::findOrCreateByTelegramId($telegramUserId, $username);

        // Register UpdateMemoryTool for this user
        $updateMemoryTool = new Tools\UpdateMemoryTool($telegramUserId);
        $this->toolRegistry->register($updateMemoryTool);

        // Register ExecuteSqlQueryTool for this user
        $executeSqlQueryTool = new Tools\ExecuteSqlQueryTool($telegramUserId);
        $this->toolRegistry->register($executeSqlQueryTool);

        // Register GetChatHistoryTool for this chat
        if ($telegramChatId) {
            $getChatHistoryTool = new Tools\GetChatHistoryTool($telegramChatId);
            $this->toolRegistry->register($getChatHistoryTool);
        }

        // Load memory context
        $memoryContext = $this->memoryService->composeMemoryContext($telegramUser);

        // Prepare system prompt
        $systemPrompt = $this->getSystemPrompt($memoryContext);

        // Inject current date/time into user message so the model reliably knows the date
        $now = now()->timezone('Europe/Moscow');
        $datePrefix = "[Current date: {$now->format('Y-m-d')}, time: {$now->format('H:i')} MSK]";

        // Prepare initial messages (without system - it's passed separately)
        $messages = [
            [
                'role' => 'user',
                'content' => "{$datePrefix}\n\n{$userMessage}",
            ],
        ];

        // Save user message to chat history
        if ($telegramChatId) {
            TelegramChatMessage::create([
                'telegram_chat_id' => $telegramChatId,
                'telegram_user_id' => $telegramUser->telegram_user_id,
                'role' => 'user',
                'content' => $userMessage,
            ]);
        }

        // Get available tools
        $tools = $this->toolRegistry->getToolsForLLM();

        $iteration = 0;
        $finalAnswer = null;

        Log::info('Agent loop started', [
            'telegram_user_id' => $telegramUserId,
            'message' => $userMessage,
            'tools_count' => count($tools),
            'system_prompt_length' => strlen($systemPrompt),
        ]);

        // Agent loop
        while ($iteration < self::MAX_ITERATIONS) {
            // Check if stop was requested
            if ($this->isStopRequested($telegramUserId)) {
                Log::info('Agent loop stopped by user request', [
                    'telegram_user_id' => $telegramUserId,
                    'iteration' => $iteration,
                ]);
                $this->clearStopFlag($telegramUserId);

                return '⛔️ Processing stopped by your request.';
            }

            $iteration++;

            Log::info('Agent loop iteration', [
                'iteration' => $iteration,
                'messages_count' => count($messages),
            ]);

            try {
                // Observation masking: replace old tool outputs with placeholders
                if ($iteration > 1) {
                    $this->maskOldToolResults($messages);
                }

                // Token budget check: mask aggressively or abort if critical
                if (! $this->enforceTokenBudget($messages, $systemPrompt)) {
                    Log::warning('Agent loop terminated by token budget', ['iteration' => $iteration]);

                    // Try to get whatever the LLM can produce with remaining context
                    break;
                }

                // Call LLM
                $response = OpenRouterClient::chatWithTools(
                    $messages,
                    $tools,
                    self::MODEL,
                    4096,
                    $systemPrompt
                );

                $assistantMessage = $response['choices'][0]['message'] ?? null;

                if (! $assistantMessage) {
                    Log::error('No assistant message in response');
                    break;
                }

                // Add assistant message to history
                $messages[] = $assistantMessage;

                // Check for tool calls
                if (! empty($assistantMessage['tool_calls'])) {
                    Log::info('Tool calls detected', [
                        'count' => count($assistantMessage['tool_calls']),
                    ]);

                    // Execute each tool call
                    foreach ($assistantMessage['tool_calls'] as $toolCall) {
                        $toolName = $toolCall['function']['name'] ?? null;
                        $toolArgs = json_decode($toolCall['function']['arguments'] ?? '{}', true) ?? [];
                        $toolCallId = $toolCall['id'] ?? 'unknown';

                        Log::info('Executing tool', [
                            'tool' => $toolName,
                            'args' => $toolArgs,
                        ]);

                        $tool = $this->toolRegistry->get($toolName);

                        if (! $tool) {
                            $toolResult = [
                                'success' => false,
                                'error' => "Tool '{$toolName}' not found",
                            ];
                        } else {
                            try {
                                $toolResult = $tool->execute($toolArgs);
                            } catch (\Exception $e) {
                                Log::error('Tool execution failed', [
                                    'tool' => $toolName,
                                    'error' => $e->getMessage(),
                                ]);

                                $toolResult = [
                                    'success' => false,
                                    'error' => $e->getMessage(),
                                ];
                            }
                        }

                        // Add tool result to messages (with truncation for large results)
                        $messages[] = [
                            'role' => 'tool',
                            'tool_call_id' => $toolCallId,
                            'content' => $this->truncateToolResult($toolResult, $toolName),
                        ];
                    }

                    // Continue loop to let LLM process tool results
                    continue;
                }

                // No tool calls, check for final answer
                $content = $assistantMessage['content'] ?? '';

                if ($content) {
                    $finalAnswer = $content;
                    Log::info('Final answer received', [
                        'length' => strlen($finalAnswer),
                    ]);
                    break;
                }

                // No content and no tool calls - something went wrong
                Log::warning('No content and no tool calls in assistant message');
                break;
            } catch (\Exception $e) {
                Log::error('Agent loop error', [
                    'iteration' => $iteration,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                return "Sorry, I encountered an error processing your request: {$e->getMessage()}";
            }
        }

        if ($iteration >= self::MAX_ITERATIONS) {
            Log::warning('Agent loop reached max iterations');

            return "Sorry, I couldn't complete your request within the maximum number of steps.";
        }

        if (! $finalAnswer) {
            Log::warning('No final answer generated');

            return "Sorry, I couldn't generate a response.";
        }

        // Save assistant response to chat history
        if ($telegramChatId && $finalAnswer) {
            TelegramChatMessage::create([
                'telegram_chat_id' => $telegramChatId,
                'telegram_user_id' => null,
                'role' => 'assistant',
                'content' => $finalAnswer,
            ]);
        }

        Log::info('Agent loop completed', [
            'iterations' => $iteration,
            'final_answer_length' => strlen($finalAnswer),
        ]);

        return $finalAnswer;
    }

    /**
     * Estimate token count for messages array (rough: 1 token ≈ 4 chars)
     */
    private function estimateTokens(array $messages, ?string $systemPrompt = null): int
    {
        $chars = strlen($systemPrompt ?? '');

        foreach ($messages as $message) {
            $content = $message['content'] ?? '';
            $chars += is_string($content) ? strlen($content) : strlen(json_encode($content));

            if (! empty($message['tool_calls'])) {
                $chars += strlen(json_encode($message['tool_calls']));
            }
        }

        return (int) ceil($chars / 4);
    }

    /**
     * Truncate a tool result if it exceeds the max size.
     * Returns JSON string ready to be used as message content.
     */
    private function truncateToolResult(mixed $toolResult, string $toolName): string
    {
        $encoded = json_encode($toolResult);

        if (strlen($encoded) <= self::MAX_TOOL_RESULT_CHARS) {
            return $encoded;
        }

        Log::info('Truncating large tool result', [
            'tool' => $toolName,
            'original_size' => strlen($encoded),
            'max_size' => self::MAX_TOOL_RESULT_CHARS,
        ]);

        // For transcript tool — keep metadata, truncate entries
        if ($toolName === 'get_transcript' && is_array($toolResult) && isset($toolResult['transcript'])) {
            $entries = $toolResult['transcript'];
            $totalEntries = count($entries);

            // Keep first 10 and last 10 entries
            $kept = 20;
            if ($totalEntries > $kept) {
                $head = array_slice($entries, 0, 10);
                $tail = array_slice($entries, -10);
                $toolResult['transcript'] = array_merge(
                    $head,
                    [['speaker' => 'SYSTEM', 'text' => "[...{$totalEntries} entries total, " . ($totalEntries - $kept) . " omitted...]", 'timestamp' => null]],
                    $tail,
                );
                $toolResult['_truncated'] = true;
                $toolResult['_total_entries'] = $totalEntries;
            }

            return json_encode($toolResult);
        }

        // Generic truncation — cut in the middle
        $half = (int) (self::MAX_TOOL_RESULT_CHARS / 2);

        return substr($encoded, 0, $half)
            . "\n\n...[TRUNCATED: original size " . strlen($encoded) . " chars]...\n\n"
            . substr($encoded, -$half);
    }

    /**
     * Mask old tool results in messages to free up context space.
     * Keeps only the last $keepRecent tool results verbatim, replaces older ones with placeholders.
     */
    private function maskOldToolResults(array &$messages, int $keepRecent = 2): void
    {
        // Find all tool message indices
        $toolIndices = [];
        foreach ($messages as $i => $msg) {
            if (($msg['role'] ?? '') === 'tool') {
                $toolIndices[] = $i;
            }
        }

        // Keep only the last N, mask the rest
        $toMask = array_slice($toolIndices, 0, max(0, count($toolIndices) - $keepRecent));

        foreach ($toMask as $i) {
            $originalSize = strlen($messages[$i]['content'] ?? '');
            if ($originalSize > 200) { // Don't mask tiny results
                $messages[$i]['content'] = json_encode([
                    '_masked' => true,
                    '_note' => 'Previous tool output omitted for brevity. Result was processed in earlier iteration.',
                    '_original_size' => $originalSize,
                ]);
            }
        }
    }

    /**
     * Enforce token budget: aggressively mask if approaching limit, return false if critical.
     */
    private function enforceTokenBudget(array &$messages, ?string $systemPrompt): bool
    {
        $estimatedTokens = $this->estimateTokens($messages, $systemPrompt);
        $warningLimit = (int) (self::MODEL_CONTEXT_LIMIT * self::TOKEN_BUDGET_WARNING_THRESHOLD);
        $criticalLimit = (int) (self::MODEL_CONTEXT_LIMIT * self::TOKEN_BUDGET_CRITICAL_THRESHOLD);

        if ($estimatedTokens > $criticalLimit) {
            // Last resort: mask everything except the very last tool result
            $this->maskOldToolResults($messages, 1);
            $estimatedTokens = $this->estimateTokens($messages, $systemPrompt);

            if ($estimatedTokens > $criticalLimit) {
                Log::warning('Token budget critical — forcing loop end', [
                    'estimated_tokens' => $estimatedTokens,
                    'critical_limit' => $criticalLimit,
                ]);

                return false;
            }
        } elseif ($estimatedTokens > $warningLimit) {
            Log::info('Token budget warning — masking old tool results', [
                'estimated_tokens' => $estimatedTokens,
                'warning_limit' => $warningLimit,
            ]);
            $this->maskOldToolResults($messages, 1);
        }

        return true;
    }

    private function getSystemPrompt(string $memoryContext): string
    {
        $now = now()->timezone('Europe/Moscow');
        $currentDate = $now->translatedFormat('l, d F Y');
        $currentTime = $now->format('H:i');

        return <<<PROMPT
You are a helpful AI assistant integrated with a Telegram bot. You have access to various tools to help answer user questions.

## Current Date and Time

Today is {$currentDate}, {$currentTime} (MSK, Moscow Time, UTC+3).

{$memoryContext}

## Your Capabilities

You have access to various tools that allow you to:
- Retrieve and analyze data from the platform
- Access user information, teams, meetings, and transcripts
- Execute database queries to get specific information
- Manage conversation history and memory about users

## Memory Management - IMPORTANT

When the user shares important information, you MUST update your memory using the `update_memory` tool.

**How memory works:**
- You see your previous memory in the "Previous Context" section above
- When you learn something new, you call `update_memory` with the COMPLETE updated text
- The memory should be written as notes to yourself about this user
- Include BOTH old information (from previous context) AND new information
- Write in natural language, as instructions to yourself

**Example:**

Previous context: "This user wants me to call them John. The user is a backend developer."

User says: "I want brief, professional responses with no familiarity"

You call `update_memory` with:
```
This user wants me to call them John. The user is a backend developer. The user prefers brief, professional responses without any familiarity. Keep answers concise and strictly on topic.
```

**When to update memory:**
- User shares personal information (name, role, background)
- User specifies communication preferences
- User mentions ongoing projects or context
- User asks you to remember something
- Any other information that would help you serve them better in future conversations

**Important:**
- Always include previous context in your updates (don't lose old information)
- Write memory as natural text, not as bullet points or structured data
- Be concise but complete
- Confirm briefly what you've saved

## Guidelines

- Use tools when you need specific information to answer questions
- ALWAYS check your memory at the start and follow preferences stored there
- Adapt your communication style based on what you know about the user
- When you use tools, explain what information you found
- **CRITICAL**: Update your memory whenever you learn something important about the user

PROMPT;
    }
}
