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
use App\Services\LlmPromptService;
use App\Services\OpenRouterClient;
use App\Support\NameNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AgentService
{
    private const MAX_ITERATIONS = 25;

    private const STOP_KEY_PREFIX = 'agent_stop_';

    /** Maximum characters for a single tool result before truncation */
    private const MAX_TOOL_RESULT_CHARS = 15000;

    /** Fallback context window in tokens when a model isn't listed in config. */
    private const DEFAULT_MODEL_CONTEXT_LIMIT = 200000;

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
        // TTL must outlive a full run so the flag is still present when the worker
        // reaches its next stop checkpoint, even on long iterations.
        $ttl = (int) config('agent.run.stop_flag_ttl_seconds', 600);
        Cache::put(self::STOP_KEY_PREFIX.$userId, true, $ttl);
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

        $this->registerDefaultTools($user, $channel, $options->organizationId, $options->enableSqlTool, $options->teamId, $options->agentTaskRunId);

        if ($directMessageResponse = $this->tryHandleDirectMessageCommand($user, $content, $options)) {
            $this->logAgentRunCompleted($user, $options, $directMessageResponse, 0);

            return $directMessageResponse;
        }

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

        // Two-phase tool routing: for interactive runs, prune the toolset to the
        // categories relevant to this message so we don't send ~50 tool schemas
        // on every LLM call. Returns null (keep everything) on any failure.
        if ($options->taskType === AgentTaskType::INTERACTIVE) {
            // Per-run reset of the interactive issue-search budget (keyed under runId=0) so the
            // static counter doesn't leak across chat turns in a long-lived worker process.
            \App\Services\Agent\Support\AgentRunToolBudget::reset(0);

            $selectedTools = app(AgentToolRouter::class)->selectToolNames($content);
            if ($selectedTools !== null) {
                $this->toolRegistry->keepOnly($selectedTools);
            }
            // Autonomous-write commit-report tools are never reachable from interactive chat,
            // even when the router falls back to the full toolset (selectToolNames returns null).
            $this->toolRegistry->forget(['save_commit_report', 'update_commit_report_item']);
        } elseif (! empty($options->allowedTools)) {
            // Non-interactive (agent-task) runs honor the task's allowed_tools as a HARD
            // allowlist — matching the ISOLATED path (AgentTaskToolExecutor::makeRegistry).
            // Without this an inline background run is offered the full ~50-tool set.
            $this->toolRegistry->keepOnly($options->allowedTools);
        } else {
            // No explicit allowlist (null or hard-empty []): keep the framework's full-toolset
            // default, but NEVER offer the autonomous commit-report WRITE tools to a run that did
            // not explicitly request them. The autonomous reporter/reviewer pass their tools via
            // allowed_tools and so take the keepOnly branch above.
            $this->toolRegistry->forget(['save_commit_report', 'update_commit_report_item']);
        }

        // Get available tools
        $tools = $this->toolRegistry->getToolsForLLM();

        // Resolve the active model once so the token budget and per-call timeout
        // match the model actually being used, instead of a hardcoded assumption.
        $resolvedModel = $this->modelRouter->resolve($options->taskType);
        $contextLimit = $this->resolveModelContextLimit($resolvedModel);

        // Wall-clock budget applies to interactive runs only; agent-task runs keep
        // their own long timeouts and are unaffected.
        $isInteractive = $options->taskType === AgentTaskType::INTERACTIVE;
        $runStartedAt = microtime(true);
        $maxRunSeconds = (int) config('agent.run.max_seconds', 180);
        $llmTimeoutSeconds = $isInteractive
            ? (int) config('agent.run.llm_timeout_seconds', 180)
            : null;

        $iteration = 0;
        $finalAnswer = null;
        $iterationToolCounts = [];  // [iteration_number => count_of_tool_messages_added]
        $inRunMemoryCompacted = false;
        // Lethal-trifecta gate: a run becomes "tainted" once untrusted content is read
        // this run (or starts tainted for untrusted input like a Telegram group). While
        // tainted, high-impact tools (outbound/mutation) are blocked, not executed.
        $tainted = $options->untrustedInput;

        Log::info('Agent loop started', [
            'user_id' => $user->id,
            'channel' => $channel,
            'message' => $content,
            'tools_count' => count($tools),
            'model' => $resolvedModel,
            'context_limit' => $contextLimit,
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

            // Wall-clock budget for interactive runs: stop starting new iterations
            // once we've spent the allotted time and return whatever we have.
            if ($isInteractive && $maxRunSeconds > 0 && (microtime(true) - $runStartedAt) > $maxRunSeconds) {
                Log::warning('Agent loop terminated by wall-clock budget', [
                    'iteration' => $iteration,
                    'elapsed_seconds' => round(microtime(true) - $runStartedAt, 1),
                    'max_seconds' => $maxRunSeconds,
                ]);

                break;
            }

            $iteration++;

            Log::info('Agent loop iteration', [
                'iteration' => $iteration,
                'messages_count' => count($messages),
            ]);

            try {
                // Observation masking: keep all tool results from last N iterations,
                // preventing hallucinations when LLM makes parallel calls in one iteration.
                if ($iteration > 1) {
                    $this->maskOldToolResultsByIteration(
                        $messages,
                        $iterationToolCounts,
                        (int) config('agent.in_run_masking.keep_recent_iterations', 2)
                    );
                }

                // Thinking block masking: strip reasoning_details from old assistant messages
                if ($options->enableThinking) {
                    $messages = $this->maskOldThinkingBlocks($messages);
                }

                // Token budget check: mask aggressively or abort if critical
                if (! $this->enforceTokenBudget($messages, $systemPrompt, $contextLimit)) {
                    Log::warning('Agent loop terminated by token budget', ['iteration' => $iteration]);

                    // Try to get whatever the LLM can produce with remaining context
                    break;
                }

                // In-run LLM compaction: compress accumulated facts when run is long
                $this->tryInRunCompaction(
                    $messages,
                    $systemPrompt,
                    $iterationToolCounts,
                    $inRunMemoryCompacted,
                    $iteration,
                    $user,
                    $options,
                );

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
                    $resolvedModel,
                    $options->maxTokens,
                    $systemPrompt,
                    $extraPayload,
                    $llmTimeoutSeconds,
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
                        // Honor /stop between tools so long multi-tool iterations can be
                        // interrupted promptly, not only at the next loop boundary.
                        if ($this->isStopRequested($user->id)) {
                            Log::info('Agent loop stopped by user request (mid-iteration)', [
                                'user_id' => $user->id,
                                'iteration' => $iteration,
                            ]);
                            $this->clearStopFlag($user->id);

                            return '⛔️ Processing stopped by your request.';
                        }

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
                        } elseif ($tainted && $tool instanceof \App\Services\Agent\Tools\Contracts\HighImpactAgentTool) {
                            // Lethal-trifecta gate: untrusted content was read this run, so a
                            // high-impact (outbound/mutation) action must not run automatically.
                            Log::warning('High-impact tool blocked in tainted run', [
                                'tool' => $toolName,
                                'user_id' => $user->id,
                            ]);

                            $toolResult = [
                                'success' => false,
                                'requires_human_confirmation' => true,
                                'blocked_tool' => $toolName,
                                'error' => 'Заблокировано в целях безопасности: в этом диалоге был обработан недоверенный '
                                    .'контент (транскрипт встречи или групповой чат), поэтому действие, отправляющее '
                                    .'сообщение или меняющее данные, нельзя выполнить автоматически. Сообщи пользователю, '
                                    .'что это действие нужно подтвердить или выполнить вручную.',
                            ];
                        } else {
                            try {
                                $toolResult = $tool->execute($toolArgs);

                                // Reading untrusted content taints the rest of the run.
                                if ($tool instanceof \App\Services\Agent\Tools\Contracts\ReturnsUntrustedContent
                                    && is_array($toolResult) && ($toolResult['success'] ?? false) === true) {
                                    $tainted = true;
                                }
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
                        $iterationToolCounts[$iteration] = ($iterationToolCounts[$iteration] ?? 0) + 1;

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
                        'preview' => mb_substr($assistantMessage['reasoning'], 0, 500),
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

    private function tryHandleDirectMessageCommand(User $user, string $content, AgentRunOptions $options): ?string
    {
        if (! preg_match('/^\s*отправь\s+сообщение\s+(.+?)\s*[-—:]\s*(.+?)\s*$/iu', $content, $matches)) {
            return null;
        }

        $target = trim($matches[1]);
        $message = trim($matches[2]);

        if ($target === '' || $message === '') {
            return null;
        }

        $targetNorm = NameNormalizer::normalize($target);
        $currentNameNorm = NameNormalizer::normalize((string) $user->name);
        $parameters = ['content' => $message];

        if ($currentNameNorm !== '' && (str_starts_with($targetNorm, $currentNameNorm) || str_starts_with($currentNameNorm, $targetNorm))) {
            $parameters['target_user_id'] = $user->id;
        } else {
            $parameters['target_name'] = $target;
        }

        $tool = $this->toolRegistry->get('send_user_message');
        if (! $tool) {
            return null;
        }

        $result = $tool->execute($parameters);
        $success = is_array($result) && (bool) ($result['success'] ?? false);
        $this->logToolActivity($user, $options, 'send_user_message', $parameters, $result);

        if (! $success) {
            $error = is_array($result) ? (string) ($result['error'] ?? 'неизвестная ошибка') : 'неизвестная ошибка';

            return "Не удалось отправить сообщение: {$error}";
        }

        $recipientName = (string) data_get($result, 'recipient.name', $target);
        $channelType = (string) data_get($result, 'conversation.channel_type', '');
        $channelLabel = $channelType === 'telegram' ? 'Telegram' : 'веб-чат';

        return "✅ Сообщение «{$message}» отправлено {$recipientName} в {$channelLabel}.";
    }

    private function registerDefaultTools(User $user, ?string $channel, ?int $organizationId = null, bool $enableSqlTool = true, ?int $teamId = null, ?int $agentTaskRunId = null): void
    {
        $this->toolRegistrar->registerDefaults($this->toolRegistry, $user, $channel, organizationId: $organizationId, teamId: $teamId, enableSqlTool: $enableSqlTool, agentTaskRunId: $agentTaskRunId);
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
     * Estimate token count for messages array.
     *
     * Counts characters with mb_strlen (not bytes) so Cyrillic text — where each
     * char is 2 bytes in UTF-8 — isn't over-counted ~2x, which would otherwise
     * trigger premature masking/compaction on Russian conversations.
     */
    private function estimateTokens(array $messages, ?string $systemPrompt = null): int
    {
        $chars = mb_strlen($systemPrompt ?? '');

        foreach ($messages as $message) {
            $content = $message['content'] ?? '';
            $chars += is_string($content) ? mb_strlen($content) : mb_strlen(json_encode($content));

            if (! empty($message['tool_calls'])) {
                $chars += mb_strlen(json_encode($message['tool_calls']));
            }

            // Count thinking tokens — reasoning_details not included in content
            if (! empty($message['reasoning_details'])) {
                $chars += mb_strlen(json_encode($message['reasoning_details']));
            }

            if (! empty($message['reasoning'])) {
                $chars += mb_strlen($message['reasoning']);
            }
        }

        $charsPerToken = (float) config('agent.token_estimation.chars_per_token', 3.0);
        if ($charsPerToken <= 0) {
            $charsPerToken = 3.0;
        }

        return (int) ceil($chars / $charsPerToken);
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
     * Batch-aware masking: keep all tool results from the last N iterations.
     *
     * Uses $iterationToolCounts ([iteration => count]) to determine how many total
     * tool messages to preserve. Count-based tracking is immune to array re-indexing
     * that occurs in maskOldThinkingBlocks().
     */
    private function maskOldToolResultsByIteration(array &$messages, array $iterationToolCounts, int $keepRecentIterations = 2): void
    {
        if (empty($iterationToolCounts)) {
            return;
        }

        $toolIndices = [];
        foreach ($messages as $i => $msg) {
            if (($msg['role'] ?? '') === 'tool') {
                $toolIndices[] = $i;
            }
        }

        if (empty($toolIndices)) {
            return;
        }

        $maxIteration = max(array_keys($iterationToolCounts));
        $keepFromIteration = max(1, $maxIteration - $keepRecentIterations + 1);

        $keepCount = 0;
        foreach ($iterationToolCounts as $iter => $count) {
            if ($iter >= $keepFromIteration) {
                $keepCount += $count;
            }
        }

        $toMask = array_slice($toolIndices, 0, max(0, count($toolIndices) - $keepCount));

        foreach ($toMask as $i) {
            $originalSize = strlen($messages[$i]['content'] ?? '');
            if ($originalSize > 200) {
                $messages[$i]['content'] = json_encode([
                    '_masked' => true,
                    '_note' => 'Previous tool output omitted for brevity. Result was processed in earlier iteration.',
                    '_original_size' => $originalSize,
                ]);
            }
        }
    }

    /**
     * In-run LLM compaction: compress accumulated tool results into a compact
     * working memory block injected into the system prompt.
     *
     * Triggers once when $iteration >= threshold and there are tool results to summarize.
     * On failure, sets the flag anyway to prevent repeated attempts.
     */
    private function tryInRunCompaction(
        array &$messages,
        string &$systemPrompt,
        array &$iterationToolCounts,
        bool &$inRunMemoryCompacted,
        int $iteration,
        User $user,
        AgentRunOptions $options,
    ): void {
        if ($inRunMemoryCompacted) {
            return;
        }

        if (! config('agent.in_run_compaction.enabled', false)) {
            return;
        }

        if ($iteration < (int) config('agent.in_run_compaction.threshold', 7)) {
            return;
        }

        if (empty($iterationToolCounts)) {
            return;
        }

        try {
            $summary = $this->buildInRunCompactionSummary($messages);

            if ($summary) {
                $systemPrompt .= "\n\n<in_run_memory>\n{$summary}\n</in_run_memory>";
                // Mask ALL old tool results now that facts are in working memory
                $this->maskOldToolResults($messages, 0);
                // Reset iteration tracking so batch-aware masking starts fresh
                $iterationToolCounts = [];
            }

            $inRunMemoryCompacted = true;

            $this->logToolActivity($user, $options, 'in_run_compaction_triggered', [
                'iteration' => $iteration,
                'summary_length' => strlen($summary ?? ''),
            ], ['success' => true]);

            Log::info('In-run context compaction completed', [
                'iteration' => $iteration,
                'summary_length' => strlen($summary ?? ''),
            ]);
        } catch (\Exception $e) {
            Log::warning('In-run compaction failed, continuing without compaction', [
                'iteration' => $iteration,
                'error' => $e->getMessage(),
            ]);
            $inRunMemoryCompacted = true;
        }
    }

    /**
     * Build a compact summary of accumulated tool results for in-run context compaction.
     * Preserves entity IDs (user_id, profile_id, etc.) so the LLM can continue
     * making tool calls without re-fetching already-retrieved data.
     */
    private function buildInRunCompactionSummary(array $messages): ?string
    {
        $toolContents = [];
        foreach ($messages as $msg) {
            if (($msg['role'] ?? '') === 'tool') {
                $content = $msg['content'] ?? '';
                // Skip already-masked results — they were processed in earlier iterations
                $decoded = json_decode($content, true);
                if (is_array($decoded) && ($decoded['_masked'] ?? false)) {
                    continue;
                }
                $toolContents[] = $content;
            }
        }

        if (empty($toolContents)) {
            return null;
        }

        $compactionPrompt = "Review these tool call results from an ongoing conversation and extract a structured working memory.\n\n"
            .'CRITICAL: Preserve ALL entity IDs exactly as they appear (user_id, profile_id, team_id, calendar_event_id, issue_id) '
            ."— these are required for subsequent API calls.\n\n"
            ."Format your response as JSON:\n"
            ."{\n"
            ."  \"people\": [{\"name\": \"...\", \"user_id\": X, \"profile_id\": X, \"role\": \"...\"}],\n"
            ."  \"entities\": [{\"type\": \"team|meeting|issue\", \"id\": X, \"name\": \"...\", \"key_facts\": \"...\"}],\n"
            ."  \"decisions\": [\"...\"],\n"
            ."  \"current_task\": \"what was asked and what has been retrieved so far\"\n"
            ."}\n\n"
            ."Omit fields with no data. Tool results:\n\n"
            .implode("\n---\n", $toolContents);

        $model = config('agent.in_run_compaction.model', config('agent.models.extraction', 'openai/gpt-4.1-mini'));
        $maxTokens = (int) config('agent.in_run_compaction.max_summary_tokens', 800);

        $result = app(OpenRouterClient::class)->chat(
            [['role' => 'user', 'content' => $compactionPrompt]],
            $model,
            $maxTokens,
        );

        return $result ?: null;
    }

    /**
     * Mask old tool results in messages to free up context space.
     * Keeps only the last $keepRecent tool results verbatim, replaces older ones with placeholders.
     * Used by enforceTokenBudget() for emergency count-based masking.
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
     * Resolve the approximate context window (tokens) for a model id.
     */
    private function resolveModelContextLimit(?string $model): int
    {
        $limits = (array) config('agent.model_context_limits', []);
        $default = (int) ($limits['default'] ?? self::DEFAULT_MODEL_CONTEXT_LIMIT);

        if ($model === null || $model === '') {
            return $default;
        }

        return (int) ($limits[$model] ?? $default);
    }

    /**
     * Enforce token budget: aggressively mask if approaching limit, return false if critical.
     */
    private function enforceTokenBudget(array &$messages, ?string $systemPrompt, ?int $contextLimit = null): bool
    {
        $contextLimit = $contextLimit ?? self::DEFAULT_MODEL_CONTEXT_LIMIT;
        $estimatedTokens = $this->estimateTokens($messages, $systemPrompt);
        $warningLimit = (int) ($contextLimit * self::TOKEN_BUDGET_WARNING_THRESHOLD);
        $criticalLimit = (int) ($contextLimit * self::TOKEN_BUDGET_CRITICAL_THRESHOLD);

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
            if (in_array($toolName, ['query_db', 'query_data'], true) && str_contains($error, 'not found')) {
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
                    if (in_array($toolName, ['query_db', 'query_data'], true)) {
                        $issues[] = 'Hint: Empty results might mean wrong filter parameters, or the data truly doesn\'t exist. Consider verifying parameters.';
                    }
                }
            }
        }

        // Check 3: Missing expected fields
        $expectedFieldsByTool = [
            'get_transcript' => ['event', 'transcript'],
            'query_db' => ['success'],
            'query_data' => ['success'],
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
            $this->promptRoleSection($organizationId),
            $this->promptContextSection($user, $profileId, $organizationId),
            $memoryContext ? "<memory>\n{$memoryContext}\n</memory>" : null,
            $compactedHistorySummary ? "<earlier_conversation>\n{$compactedHistorySummary}\n</earlier_conversation>" : null,
            $systemPromptExtension ? "<task_context>\n{$systemPromptExtension}\n</task_context>" : null,
            $this->promptThinkFirstSection($organizationId),
            $this->promptIdRulesSection($user->name ?? 'Unknown', $user->id, $profileId, $organizationId),
            $this->promptDatabaseSchemaSection($organizationId),
            $this->promptToolGuidanceSection($organizationId),
            $this->promptProactiveModeSection($organizationId),
            $this->promptFormattingSection($mode, $organizationId),
        ]);

        return app(LlmPromptService::class)->renderView(
            slug: 'agent.system',
            organizationId: $organizationId,
            fallbackView: 'llm-prompts.agent.system',
            variables: ['sections' => implode("\n\n", $sections)],
            name: 'Agent system prompt',
        );
    }

    private function promptRoleSection(?int $organizationId): string
    {
        return app(LlmPromptService::class)->renderView(
            slug: 'agent.section.role',
            organizationId: $organizationId,
            fallbackView: 'llm-prompts.agent.section-role',
            name: 'Agent role section',
        );
    }

    private function promptContextSection(User $user, ?int $profileId, ?int $organizationId): string
    {
        $now = now()->timezone('Europe/Moscow');
        $currentDate = $now->translatedFormat('l, d F Y');
        $currentTime = $now->format('H:i');
        $userName = $user->name ?? 'Unknown';
        $userId = $user->id;
        $profileHint = $profileId ? ", profile_id={$profileId}" : '';

        $contextBlock = app(LlmPromptService::class)->renderView(
            slug: 'agent.section.context',
            organizationId: $organizationId,
            fallbackView: 'llm-prompts.agent.section-context',
            variables: [
                'current_date' => $currentDate,
                'current_time' => $currentTime,
                'user_name' => $userName,
                'user_id' => $userId,
                'profile_hint' => $profileHint,
            ],
            name: 'Agent context section',
        );

        if (! $organizationId) {
            return $contextBlock;
        }

        $org = Organization::with([
            'users' => fn ($q) => $q->select('users.id', 'users.name', 'users.email'),
            'links',
        ])->find($organizationId);
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
            '| %s | %d | %s | %s |',
            $u->name,
            $u->id,
            $profileMap[$u->id] ?? '—',
            $u->email ?? '—',
        ))->join("\n");

        $rosterBlock = app(LlmPromptService::class)->renderView(
            slug: 'agent.section.team_roster',
            organizationId: $organizationId,
            fallbackView: 'llm-prompts.agent.section-team-roster',
            variables: [
                'organization_name' => $org->name,
                'rows' => $rows,
            ],
            name: 'Agent team roster section',
        );

        $links = $org->links()->pluck('url');
        $linksBlock = $links->isNotEmpty()
            ? app(LlmPromptService::class)->renderView(
                slug: 'agent.section.org_links',
                organizationId: $organizationId,
                fallbackView: 'llm-prompts.agent.section-org-links',
                variables: ['links' => $links->map(fn ($url) => "- {$url}")->join("\n")],
                name: 'Agent organization links section',
            )
            : null;

        return implode("\n\n", array_filter([$contextBlock, $rosterBlock, $linksBlock]));
    }

    private function promptThinkFirstSection(?int $organizationId): string
    {
        return app(LlmPromptService::class)->renderView(
            slug: 'agent.section.think_first',
            organizationId: $organizationId,
            fallbackView: 'llm-prompts.agent.section-think-first',
            name: 'Agent think first section',
        );
    }

    private function promptIdRulesSection(string $userName, int $userId, ?int $profileId, ?int $organizationId): string
    {
        $profileRef = $profileId ? "profile_id={$profileId}" : 'no profile yet';

        return app(LlmPromptService::class)->renderView(
            slug: 'agent.section.id_rules',
            organizationId: $organizationId,
            fallbackView: 'llm-prompts.agent.section-id-rules',
            variables: [
                'user_name' => $userName,
                'user_id' => $userId,
                'profile_ref' => $profileRef,
            ],
            name: 'Agent ID rules section',
        );
    }

    private function promptDatabaseSchemaSection(?int $organizationId): string
    {
        $dbSchema = $this->databaseSchemaService->getSchemaForAgent();

        return app(LlmPromptService::class)->renderView(
            slug: 'agent.section.db_schema',
            organizationId: $organizationId,
            fallbackView: 'llm-prompts.agent.section-db-schema',
            variables: ['db_schema' => $dbSchema],
            name: 'Agent database schema section',
        );
    }

    private function promptToolGuidanceSection(?int $organizationId): string
    {
        return app(LlmPromptService::class)->renderView(
            slug: 'agent.section.tool_guidance',
            organizationId: $organizationId,
            fallbackView: 'llm-prompts.agent.section-tool-guidance',
            name: 'Agent tool guidance section',
        );
    }

    private function promptProactiveModeSection(?int $organizationId): string
    {
        return app(LlmPromptService::class)->renderView(
            slug: 'agent.section.proactive_mode',
            organizationId: $organizationId,
            fallbackView: 'llm-prompts.agent.section-proactive-mode',
            name: 'Agent proactive mode section',
        );
    }

    private function promptFormattingSection(OutputMode $mode, ?int $organizationId): string
    {
        $rules = $mode === OutputMode::MD
            ? 'Use Markdown: headings, bullet lists, bold, italic, code blocks where appropriate.'
            : 'Plain text only. No Markdown syntax (no **, ##, backticks, bullet dashes).';

        return app(LlmPromptService::class)->renderView(
            slug: 'agent.section.formatting',
            organizationId: $organizationId,
            fallbackView: 'llm-prompts.agent.section-formatting',
            variables: ['rules' => $rules],
            name: 'Agent formatting section',
        );
    }
}
