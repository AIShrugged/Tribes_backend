<?php

namespace App\Services\Agent;

use App\Enums\AgentTaskType;
use App\Enums\OutputMode;
use App\Models\AgentActivityLog;
use App\Models\Chat;
use App\Models\Organization;
use App\Models\Profile;
use App\Models\User;
use App\Services\Agent\Tools\ToolRegistry;
use App\Services\Artifact\ArtifactStateService;
use App\Services\Chat\PageContextFormatter;
use App\Services\OpenRouterClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AgentService
{
    private const MAX_ITERATIONS = 25;

    private const STOP_KEY_PREFIX = 'agent_stop_';

    /** Maximum characters for a single tool result before truncation */
    private const MAX_TOOL_RESULT_CHARS = 15000;

    /** Approximate model context window in tokens (Claude 3.5 Sonnet = 200K) */
    private const MODEL_CONTEXT_LIMIT = 200000;

    /** Start masking aggressively when context exceeds this fraction of limit */
    private const TOKEN_BUDGET_WARNING_THRESHOLD = 0.8;

    /** Force-stop the loop when context exceeds this fraction of limit */
    private const TOKEN_BUDGET_CRITICAL_THRESHOLD = 0.9;

    private ToolRegistry $toolRegistry;

    private AgentToolRegistrar $toolRegistrar;

    private MemoryService $memoryService;

    private ArtifactStateService $artifactStateService;

    private DatabaseSchemaService $databaseSchemaService;

    private AgentModelRouter $modelRouter;

    private ConversationCompactionService $compactionService;


    private PageContextFormatter $pageContextFormatter;

    public function __construct(
        ToolRegistry $toolRegistry,
        AgentToolRegistrar $toolRegistrar,
        MemoryService $memoryService,
        ArtifactStateService $artifactStateService,
        DatabaseSchemaService $databaseSchemaService,
        AgentModelRouter $modelRouter,
        ConversationCompactionService $compactionService,
        PageContextFormatter $pageContextFormatter,
    ) {
        $this->toolRegistry = $toolRegistry;
        $this->toolRegistrar = $toolRegistrar;
        $this->memoryService = $memoryService;
        $this->artifactStateService = $artifactStateService;
        $this->databaseSchemaService = $databaseSchemaService;
        $this->modelRouter = $modelRouter;
        $this->compactionService = $compactionService;
        $this->pageContextFormatter = $pageContextFormatter;
    }

    /**
     * Register chat-specific tools (call this before processMessage when a Chat context is available).
     */
    public function registerChatTools(Chat $chat, User $user): void
    {
        $this->toolRegistrar->registerChatTools($this->toolRegistry, $chat, $user);
    }

    /**
     * Request stop for a user's current agent processing.
     */
    public function requestStop(int $userId): void
    {
        Cache::put(self::STOP_KEY_PREFIX.$userId, true, 60);
        Log::info('Stop requested for user', ['user_id' => $userId]);
    }

    /**
     * Check if stop was requested for this user.
     */
    private function isStopRequested(int $userId): bool
    {
        return Cache::get(self::STOP_KEY_PREFIX.$userId, false);
    }

    /**
     * Clear stop flag for user.
     */
    private function clearStopFlag(int $userId): void
    {
        Cache::forget(self::STOP_KEY_PREFIX.$userId);
    }

    /**
     * Process a message through the agent loop.
     *
     * Channel-agnostic: works for web chat, Telegram, or any other channel.
     * The caller is responsible for persisting messages and registering
     * channel-specific tools (e.g. GetChatHistoryTool for Telegram) into
     * the ToolRegistry before calling this method.
     *
     * @param  User  $user  Authenticated application user
     * @param  Collection  $history  Recent chat history (ChatMessage / any model with role+content)
     * @param  string  $content  The new user message
     * @param  string|null  $channel  Channel name for memory resolution (e.g. 'telegram'). Null = web.
     * @param  OutputMode  $mode  Output formatting mode: md (Markdown) or plain text.
     */
    public function processMessage(User $user, Collection $history, string $content, ?string $channel = null, OutputMode $mode = OutputMode::PLAIN): string
    {
        return $this->run(
            $user,
            $history,
            $content,
            new AgentRunOptions(
                channel: $channel,
                outputMode: $mode,
                taskType: AgentTaskType::INTERACTIVE,
            )
        );
    }

    public function run(User $user, Collection $history, string $content, AgentRunOptions $options, array $messageContext = []): string
    {
        $this->clearStopFlag($user->id);
        $this->reportProgress($options, 'started');
        $this->logAgentRunStarted($user, $options, $content, $messageContext);

        $channel = $options->channel;
        $mode = $options->outputMode;
        $systemPromptExtension = $options->systemPromptExtension;

        $this->registerDefaultTools($user, $channel, $options->organizationId, $options->enableSqlTool);

        // Load memory context
        $memoryContext = $this->memoryService->composeMemoryContext($user, $channel);

        $compactedHistory = $this->compactionService->compact($history, $options->conversationKey);

        // Resolve user identity once — used in both system prompt and message prefix
        $userId = $user->id;
        $profileId = Profile::where('user_id', $userId)->value('id');
        $userName = $user->name ?? 'Unknown';

        // Prepare system prompt
        $systemPrompt = $this->getSystemPrompt(
            $memoryContext,
            $user,
            $profileId,
            $mode,
            $compactedHistory->summary,
            $systemPromptExtension,
            $options->organizationId,
        );

        // Inject current date/time into user message so the model reliably knows the date
        $now = now()->timezone('Europe/Moscow');
        $datePrefix = "[Current date: {$now->format('Y-m-d')}, time: {$now->format('H:i')} MSK]";

        // Inject current user identity so the model can't miss it
        $userPrefix = $userName && $profileId
            ? "[Sender: {$userName} (user_id={$userId}, profile_id={$profileId})]"
            : '';

        $pageContextSection = $this->pageContextFormatter->buildPromptSection($messageContext);
        $pageContextPrefix = $pageContextSection ? "\n\n{$pageContextSection}" : '';

        // Build messages from history + current message
        $messages = [];
        foreach ($compactedHistory->messages as $msg) {
            $messages[] = [
                'role' => $msg->role === 'user' ? 'user' : 'assistant',
                'content' => $msg->content,
            ];
        }
        $messages[] = [
            'role' => 'user',
            'content' => trim("{$datePrefix} {$userPrefix}").$pageContextPrefix."\n\n{$content}",
        ];

        // Get available tools
        $tools = $this->toolRegistry->getToolsForLLM();

        $iteration = 0;
        $finalAnswer = null;

        Log::info('Agent loop started', [
            'user_id' => $user->id,
            'channel' => $channel,
            'message' => $content,
            'tools_count' => count($tools),
            'system_prompt_length' => strlen($systemPrompt),
        ]);

        // Agent loop
        while ($iteration < self::MAX_ITERATIONS) {
            // Check if stop was requested
            if ($this->isStopRequested($user->id)) {
                Log::info('Agent loop stopped by user request', [
                    'user_id' => $user->id,
                    'iteration' => $iteration,
                ]);
                $this->clearStopFlag($user->id);

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

                // Thinking block masking: strip reasoning_details from old assistant messages
                if ($options->enableThinking) {
                    $messages = $this->maskOldThinkingBlocks($messages);
                }

                // Token budget check: mask aggressively or abort if critical
                if (! $this->enforceTokenBudget($messages, $systemPrompt)) {
                    Log::warning('Agent loop terminated by token budget', ['iteration' => $iteration]);

                    // Try to get whatever the LLM can produce with remaining context
                    break;
                }

                // Build extra payload for extended thinking
                $extraPayload = null;
                if ($options->enableThinking) {
                    $extraPayload = [
                        'reasoning' => ['max_tokens' => config('ai.thinking_budget', 4000)],
                    ];
                }

                // Call LLM
                $this->reportProgress($options, 'before_llm');
                $response = app(OpenRouterClient::class)->chatWithTools(
                    $messages,
                    $tools,
                    $this->modelRouter->resolve($options->taskType),
                    $options->maxTokens,
                    $systemPrompt,
                    $extraPayload,
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
                        $this->reportProgress($options, 'before_tool', [
                            'tool' => $toolName,
                            'description' => $tool?->getDescription(),
                            'iteration' => $iteration,
                        ]);

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

                        $this->logToolActivity($user, $options, $toolName, $toolArgs, $toolResult);
                        $this->reportProgress($options, 'after_tool');

                        // Validate tool result and add feedback if issues detected (Pattern 3)
                        $validation = $this->validateToolResult($toolResult, $toolName, $toolArgs);
                        if ($validation['requires_reflection']) {
                            $this->addValidationFeedback($messages, $validation, $toolName);

                            Log::info('Tool validation detected issues', [
                                'tool' => $toolName,
                                'issues' => $validation['issues'],
                            ]);
                        }
                    }

                    // Detect repetitive failures after processing all tool calls
                    $repetitionDetection = $this->detectRepetitiveFailures($messages);
                    if ($repetitionDetection['stuck']) {
                        $this->addRepetitionWarning($messages, $repetitionDetection);
                    }

                    // Continue loop to let LLM process tool results
                    continue;
                }

                // No tool calls, check for final answer
                // When thinking is enabled, content may be an array of content blocks
                $rawContent = $assistantMessage['content'] ?? '';
                $content = is_array($rawContent)
                    ? (collect($rawContent)->firstWhere('type', 'text')['text'] ?? '')
                    : $rawContent;

                // Log thinking preview if present
                if (! empty($assistantMessage['reasoning'])) {
                    Log::info('Agent thinking', [
                        'agentRunUuid' => $options->agentRunUuid,
                        'preview'      => mb_substr($assistantMessage['reasoning'], 0, 500),
                        'length_chars' => strlen($assistantMessage['reasoning']),
                    ]);
                }

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

        Log::info('Agent loop completed', [
            'iterations' => $iteration,
            'final_answer_length' => strlen($finalAnswer),
        ]);

        $this->logAgentRunCompleted($user, $options, $finalAnswer, $iteration);

        return $finalAnswer;
    }

    private function reportProgress(AgentRunOptions $options, string $stage, array $context = []): void
    {
        $callback = $options->progressCallback;

        if (! $callback instanceof \Closure) {
            return;
        }

        $callback($stage, $context);
    }

    private function registerDefaultTools(User $user, ?string $channel, ?int $organizationId = null, bool $enableSqlTool = true): void
    {
        $this->toolRegistrar->registerDefaults($this->toolRegistry, $user, $channel, organizationId: $organizationId, enableSqlTool: $enableSqlTool);
    }

    private function logToolActivity(User $user, AgentRunOptions $options, string $toolName, array $toolArgs, mixed $toolResult): void
    {
        try {
            $success = is_array($toolResult) && ($toolResult['success'] ?? true);

            AgentActivityLog::recordActivity(
                user: $user,
                toolName: $toolName,
                toolResult: $toolResult,
                toolArgs: $toolArgs,
                chatId: $options->chatId,
                agentRunUuid: $options->agentRunUuid,
                success: $success,
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to log agent activity', ['tool' => $toolName, 'error' => $e->getMessage()]);
        }
    }

    private function logAgentRunStarted(User $user, AgentRunOptions $options, string $content, array $messageContext): void
    {
        try {
            AgentActivityLog::recordActivity(
                user: $user,
                toolName: 'agent_run_started',
                toolResult: [
                    'message_length' => mb_strlen($content),
                    'channel' => $options->channel,
                    'context_keys' => array_keys($messageContext),
                ],
                toolArgs: [
                    'task_type' => $options->taskType->value,
                    'output_mode' => $options->outputMode->value,
                ],
                chatId: $options->chatId,
                agentRunUuid: $options->agentRunUuid,
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to log agent run start', ['error' => $e->getMessage()]);
        }
    }

    private function logAgentRunCompleted(User $user, AgentRunOptions $options, string $finalAnswer, int $iterations): void
    {
        try {
            AgentActivityLog::recordActivity(
                user: $user,
                toolName: 'agent_run_completed',
                toolResult: [
                    'final_answer_length' => mb_strlen($finalAnswer),
                    'iterations' => $iterations,
                ],
                chatId: $options->chatId,
                agentRunUuid: $options->agentRunUuid,
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to log agent run completion', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Estimate token count for messages array (rough: 1 token ≈ 3.5 chars)
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

            // Count thinking tokens — reasoning_details not included in content
            if (! empty($message['reasoning_details'])) {
                $chars += strlen(json_encode($message['reasoning_details']));
            }

            if (! empty($message['reasoning'])) {
                $chars += strlen($message['reasoning']);
            }
        }

        return (int) ceil($chars / 3.5);
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
                    [['speaker' => 'SYSTEM', 'text' => "[...{$totalEntries} entries total, ".($totalEntries - $kept).' omitted...]', 'timestamp' => null]],
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
            ."\n\n...[TRUNCATED: original size ".strlen($encoded)." chars]...\n\n"
            .substr($encoded, -$half);
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
     * Strip reasoning_details and reasoning from all but the last $keepRecent assistant messages.
     * Prevents exponential token growth when extended thinking is enabled across multiple iterations.
     */
    private function maskOldThinkingBlocks(array $messages, int $keepRecent = 2): array
    {
        // Collect indices of assistant messages that contain thinking blocks
        $thinkingIndices = [];
        foreach ($messages as $i => $msg) {
            if (($msg['role'] ?? '') === 'assistant'
                && (! empty($msg['reasoning_details']) || ! empty($msg['reasoning']))
            ) {
                $thinkingIndices[] = $i;
            }
        }

        // Mask all but the last $keepRecent thinking blocks
        $toMask = array_slice($thinkingIndices, 0, max(0, count($thinkingIndices) - $keepRecent));
        foreach ($toMask as $i) {
            unset($messages[$i]['reasoning_details'], $messages[$i]['reasoning']);
        }

        return array_values($messages);
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

    /**
     * Validate tool result for common issues (Pattern 3: Validation Rules)
     * Returns validation result with hints for the LLM if issues detected.
     */
    private function validateToolResult(mixed $toolResult, string $toolName, array $toolArgs): array
    {
        $issues = [];

        // Check 1: Explicit failure
        if (is_array($toolResult) && isset($toolResult['success']) && $toolResult['success'] === false) {
            $error = $toolResult['error'] ?? 'Unknown error';
            $issues[] = "Tool returned explicit failure: {$error}";

            // Provide hints based on tool type
            if ($toolName === 'query_db' && str_contains($error, 'not found')) {
                $issues[] = 'Hint: Try broader search parameters or verify the search criteria';
            }

            if ($toolName === 'get_transcript' && str_contains($error, 'not available')) {
                $issues[] = 'Hint: Meeting might not have been recorded, or transcript is still processing';
            }
        }

        // Check 2: Empty results
        if (is_array($toolResult)) {
            // Check for empty arrays in common result fields
            $dataFields = ['data', 'results', 'items', 'transcript', 'entries', 'meetings'];
            foreach ($dataFields as $field) {
                if (isset($toolResult[$field]) && is_array($toolResult[$field]) && empty($toolResult[$field])) {
                    $issues[] = "Tool returned empty {$field} array";

                    // Context-specific hints
                    if ($toolName === 'query_db') {
                        $issues[] = 'Hint: Empty results might mean wrong filter parameters, or the data truly doesn\'t exist. Consider verifying parameters.';
                    }
                }
            }
        }

        // Check 3: Missing expected fields
        $expectedFieldsByTool = [
            'get_transcript' => ['event', 'transcript'],
            'query_db' => ['success'],
        ];

        if (isset($expectedFieldsByTool[$toolName]) && is_array($toolResult)) {
            foreach ($expectedFieldsByTool[$toolName] as $expectedField) {
                if (! isset($toolResult[$expectedField])) {
                    $issues[] = "Tool result missing expected field: {$expectedField}";
                }
            }
        }

        // Check 4: Null or empty result
        if ($toolResult === null || $toolResult === '' || $toolResult === []) {
            $issues[] = 'Tool returned null/empty result';
        }

        return [
            'valid' => empty($issues),
            'issues' => $issues,
            'requires_reflection' => ! empty($issues),
        ];
    }

    /**
     * Add validation feedback to messages to help LLM reflect on tool failure
     */
    private function addValidationFeedback(array &$messages, array $validation, string $toolName): void
    {
        if (! $validation['requires_reflection']) {
            return;
        }

        $feedbackContent = "⚠️ Tool Validation Alert for '{$toolName}':\n\n";
        foreach ($validation['issues'] as $issue) {
            $feedbackContent .= "- {$issue}\n";
        }
        $feedbackContent .= "\nConsider: Should you investigate this issue or try a different approach?";

        Log::info('Validation feedback generated', [
            'tool' => $toolName,
            'issues_count' => count($validation['issues']),
        ]);

        // Enrich the last tool message with validation notes
        $lastMessageIndex = count($messages) - 1;
        if ($lastMessageIndex >= 0 && isset($messages[$lastMessageIndex]['role']) && $messages[$lastMessageIndex]['role'] === 'tool') {
            $originalContent = $messages[$lastMessageIndex]['content'];
            $decoded = json_decode($originalContent, true);

            if (is_array($decoded)) {
                $decoded['_validation_feedback'] = $feedbackContent;
                $messages[$lastMessageIndex]['content'] = json_encode($decoded);
            }
        }
    }

    /**
     * Detect if agent is stuck in a repetitive failure loop
     * Returns array with detection result and suggestions
     */
    private function detectRepetitiveFailures(array $messages): array
    {
        // Extract last N tool calls
        $recentToolCalls = [];
        $lookbackLimit = 6; // Check last 6 tool calls

        foreach (array_reverse($messages) as $message) {
            if (count($recentToolCalls) >= $lookbackLimit) {
                break;
            }

            if (isset($message['tool_calls'])) {
                foreach ($message['tool_calls'] as $toolCall) {
                    $recentToolCalls[] = [
                        'tool' => $toolCall['function']['name'] ?? 'unknown',
                        'args' => $toolCall['function']['arguments'] ?? '{}',
                    ];
                }
            }
        }

        if (count($recentToolCalls) < 3) {
            return ['stuck' => false];
        }

        // Check for exact repetition (same tool + same args)
        $callSignatures = [];
        foreach ($recentToolCalls as $call) {
            $signature = $call['tool'].'::'.md5($call['args']);
            $callSignatures[] = $signature;
        }

        // Count occurrences
        $signatureCounts = array_count_values($callSignatures);
        $maxRepetitions = max($signatureCounts);

        if ($maxRepetitions >= 3) {
            // Same call repeated 3+ times
            $repeatedSignature = array_search($maxRepetitions, $signatureCounts);
            $repeatedCall = null;

            foreach ($recentToolCalls as $call) {
                if ($repeatedSignature === $call['tool'].'::'.md5($call['args'])) {
                    $repeatedCall = $call;
                    break;
                }
            }

            return [
                'stuck' => true,
                'pattern' => 'exact_repetition',
                'tool' => $repeatedCall['tool'] ?? 'unknown',
                'repetitions' => $maxRepetitions,
                'suggestion' => "The tool '{$repeatedCall['tool']}' has been called {$maxRepetitions} times with the same parameters. This suggests you're stuck in a loop. Try a completely different approach or tool.",
            ];
        }

        // Check for same tool different args (strategy not working)
        $toolNames = array_map(fn ($call) => $call['tool'], $recentToolCalls);
        $toolCounts = array_count_values($toolNames);
        $maxToolRepetitions = max($toolCounts);

        if ($maxToolRepetitions >= 4) {
            $repeatedTool = array_search($maxToolRepetitions, $toolCounts);

            return [
                'stuck' => true,
                'pattern' => 'same_tool_different_params',
                'tool' => $repeatedTool,
                'repetitions' => $maxToolRepetitions,
                'suggestion' => "The tool '{$repeatedTool}' has been tried {$maxToolRepetitions} times with different parameters but still not working. Consider using a different tool or asking the user for clarification.",
            ];
        }

        return ['stuck' => false];
    }

    /**
     * Add repetition warning to messages if agent is stuck
     */
    private function addRepetitionWarning(array &$messages, array $detection): void
    {
        if (! $detection['stuck']) {
            return;
        }

        Log::warning('Repetitive failure pattern detected', [
            'pattern' => $detection['pattern'],
            'tool' => $detection['tool'],
            'repetitions' => $detection['repetitions'],
        ]);

        // Add warning to last tool message
        $lastMessageIndex = count($messages) - 1;
        if ($lastMessageIndex >= 0 && isset($messages[$lastMessageIndex]['role']) && $messages[$lastMessageIndex]['role'] === 'tool') {
            $originalContent = $messages[$lastMessageIndex]['content'];
            $decoded = json_decode($originalContent, true);

            if (is_array($decoded)) {
                $decoded['_repetition_warning'] = "🔄 REPETITION DETECTED: {$detection['suggestion']}";
                $messages[$lastMessageIndex]['content'] = json_encode($decoded);
            }
        }
    }

    private function getSystemPrompt(
        string $memoryContext,
        User $user,
        ?int $profileId,
        OutputMode $mode = OutputMode::PLAIN,
        ?string $compactedHistorySummary = null,
        ?string $systemPromptExtension = null,
        ?int $organizationId = null,
    ): string {
        $sections = array_filter([
            $this->promptRoleSection(),
            $this->promptContextSection($user, $profileId, $organizationId),
            $memoryContext ? "<memory>\n{$memoryContext}\n</memory>" : null,
            $compactedHistorySummary ? "<earlier_conversation>\n{$compactedHistorySummary}\n</earlier_conversation>" : null,
            $systemPromptExtension ? "<task_context>\n{$systemPromptExtension}\n</task_context>" : null,
            $this->promptThinkFirstSection(),
            $this->promptIdRulesSection($user->name ?? 'Unknown', $user->id, $profileId),
            $this->promptDatabaseSchemaSection(),
            $this->promptToolGuidanceSection(),
            $this->promptProactiveModeSection(),
            $this->promptFormattingSection($mode),
        ]);

        return implode("\n\n", $sections);
    }

    private function promptRoleSection(): string
    {
        return <<<'XML'
<role>
You are Wanda — an AI chief of staff for engineering teams. You have full access to the team's meetings, tasks, and people data via tools. Analyze data and give the team actionable insights to make good decisions fast.

Respond in the user's language. Default to Russian for this team.
</role>
XML;
    }

    private function promptContextSection(User $user, ?int $profileId, ?int $organizationId): string
    {
        $now = now()->timezone('Europe/Moscow');
        $currentDate = $now->translatedFormat('l, d F Y');
        $currentTime = $now->format('H:i');
        $userName = $user->name ?? 'Unknown';
        $userId = $user->id;
        $profileHint = $profileId ? ", profile_id={$profileId}" : '';

        $contextBlock = "<context>\nToday: {$currentDate}, {$currentTime} MSK\nCurrent user: {$userName} (user_id={$userId}{$profileHint})\n\nWhen the user says \"me\", \"я\", \"мне\", \"мой профиль\" — they refer to {$userName} (user_id={$userId}{$profileHint}).\nDo NOT call any tool to look up the current user — IDs are already here.\n</context>";

        if (! $organizationId) {
            return $contextBlock;
        }

        $org = Organization::with(['users' => fn ($q) => $q->select('users.id', 'users.name')])->find($organizationId);
        if (! $org || $org->users->isEmpty()) {
            return $contextBlock;
        }

        $memberIds = $org->users->pluck('id');
        $profileMap = Profile::whereIn('user_id', $memberIds)
            ->orderBy('id')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($g) => $g->first()->id);

        $rows = $org->users->map(fn ($u) => sprintf(
            '| %s | %d | %s |',
            $u->name,
            $u->id,
            $profileMap[$u->id] ?? '—',
        ))->join("\n");

        $orgName = $org->name;
        $rosterBlock = "<team_roster org=\"{$orgName}\">\nUse ONLY these exact names. NEVER invent or guess names.\n\n| Name | user_id | profile_id |\n|------|---------|------------|\n{$rows}\n</team_roster>";

        return $contextBlock."\n\n".$rosterBlock;
    }

    private function promptThinkFirstSection(): string
    {
        return <<<'XML'
<think_first>
Before calling any tool, write a brief plan:
1. What does the user actually want? (one sentence)
2. What data do I need? Do I already have it from this conversation?
3. Which tools in what order? Can I combine calls?

Never call a tool "just in case". Stop after you have enough data to answer.
</think_first>
XML;
    }

    private function promptIdRulesSection(string $userName, int $userId, ?int $profileId): string
    {
        $profileRef = $profileId ? "profile_id={$profileId}" : 'no profile yet';

        return <<<XML
<id_rules>
Use only IDs returned by tools in THIS conversation. Never invent or guess IDs.
profile_id ≠ user_id. Use profile_id from meeting_summary.participants directly — no intermediate user lookup needed.
For {$userName}: user_id={$userId}, {$profileRef} — already known, no tool call needed.
If you don't have an ID — say so and offer to look it up. Never substitute a plausible-looking number.
</id_rules>
XML;
    }

    private function promptDatabaseSchemaSection(): string
    {
        $dbSchema = $this->databaseSchemaService->getSchemaForAgent();

        return <<<SQL
<db_schema>
Available tables for execute_sql_query:

```
{$dbSchema}
```

Use execute_sql_query as universal fallback when no specialized tool fits:
- Read-only SELECT only; ILIKE for case-insensitive text search; JOINs for multi-table queries
- Include `__ACCESSIBLE_USER_IDS__` in WHERE for: followups, sources, calendar_events
- Meeting action items are in issues, linked to calendar_events via sourceable_type/sourceable_id
</db_schema>
SQL;
    }

    private function promptToolGuidanceSection(): string
    {
        return <<<'XML'
<tool_guidance>
## Meetings
- query_tribes_data(entity="meeting_summary") — AI summary, decisions, discussion. Use first for any meeting question. Sufficient alone unless user explicitly asks about tasks.
- query_tribes_data(entity="tasks", filters:{calendar_event_id:X}) — action items. Only if user asks about tasks/assignments.
- query_tribes_data(entity="followups") — AI evaluation reports per participant.
- create_entity(entity="followup", data:{calendar_event_id:X}) — regenerate followup report.
- get_transcript — LAST RESORT. Explain to user why needed and ask permission first. Use only for verbatim quotes or when summary is clearly insufficient.

## People
- query_tribes_data(entity="user_insights") — long-term profile. For "who is X?", "describe X's work style".
- query_tribes_data(entity="extracted_facts") — transcript-specific facts. Requires profile_id.
- query_tribes_data(entity="insight_history") — how a person changed over time. Requires profile_id.

## Agent Memory
- query_tribes_data(entity="agent_memories") — prior agent findings (repo architecture, analysis). Filter by repo when user mentions one.

## Memory Updates
When user shares important info → call update_entity(entity="memory") with COMPLETE text (old + new). Write as notes to yourself. Confirm briefly what you saved.

## Focus
- set_user_focus — explicit priority statement only. Convert natural-language dates to YYYY-MM-DD. Do NOT infer from task patterns.
- clear_user_focus — only when user explicitly asks to clear.
- get_user_focus — only when user asks about expiry TTL (focus text is already in memory context).
- get_focused_issues — for "focused tasks", "мои фокусные задачи". Web: create_artifact(type="task_table"). Telegram: numbered list with inline links [Task](url).

When "### Urgent Tasks" appears in memory context — mention those tasks proactively in the FIRST response only. Do NOT repeat on subsequent messages.

## Daily Planning
1. Call build_daily_plan
2. Order tasks: blockers → overdue → due today → critical/high
3. Format: "Today: (1) Task — one-clause reason. Later: - Task (priority)"
4. Team plan: group by assignee. Telegram: no tables/headers, bold sections + bullets + inline links [name](url).

## Pending Issue Validations
When user message reads as an answer to a clarifying question:
1. Call get_pending_issue_validations
2. One pending + clear answer → call answer_issue_validation(issue_id, answers)
3. Multiple pending → ask which issue first
Do NOT call answer_issue_validation speculatively.

## Reflection
After each tool call — verify: Did it succeed? Does the result make sense? Is it complete?
If empty result → investigate: wrong parameters? wrong entity? different approach?
Do not accept unexpected empty results without investigation.
</tool_guidance>
XML;
    }

    private function promptProactiveModeSection(): string
    {
        return <<<'XML'
<proactive_mode>
After your main answer, scan tool results from this conversation:
- Is there a blocker the user didn't ask about?
- Is there an overdue task assigned to someone you just mentioned?
- Is there a meeting in the next 2 hours relevant to this topic?
- Is there a critical unassigned task?

If yes and clearly relevant — append 1-2 sentences:
  ⚠️ Кстати, задача X заблокирована — хочешь разберём?
  📅 Через 1.5 часа встреча по теме — подготовить agenda?

Only add if actionable. Do not repeat the same insight twice in a session.
</proactive_mode>
XML;
    }

    private function promptFormattingSection(OutputMode $mode): string
    {
        $rules = $mode === OutputMode::MD
            ? 'Use Markdown: headings, bullet lists, bold, italic, code blocks where appropriate.'
            : 'Plain text only. No Markdown syntax (no **, ##, backticks, bullet dashes).';

        return "<formatting>\n{$rules}\n</formatting>";
    }
}
