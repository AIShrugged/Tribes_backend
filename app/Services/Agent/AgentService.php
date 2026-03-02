<?php

namespace App\Services\Agent;

use App\Enums\OutputMode;
use App\Models\Chat;
use App\Models\Profile;
use App\Models\User;
use App\Services\Agent\Tools\ToolRegistry;
use App\Services\Artifact\ArtifactStateService;
use App\Services\OpenRouterClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AgentService
{
    private const MAX_ITERATIONS = 15;

    private const MODEL = 'anthropic/claude-sonnet-4.6';

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

    private MemoryService $memoryService;

    private ArtifactStateService $artifactStateService;

    private DatabaseSchemaService $databaseSchemaService;

    public function __construct(
        ToolRegistry $toolRegistry,
        MemoryService $memoryService,
        ArtifactStateService $artifactStateService,
        DatabaseSchemaService $databaseSchemaService
    ) {
        $this->toolRegistry = $toolRegistry;
        $this->memoryService = $memoryService;
        $this->artifactStateService = $artifactStateService;
        $this->databaseSchemaService = $databaseSchemaService;
    }

    /**
     * Register chat-specific tools (call this before processMessage when a Chat context is available).
     */
    public function registerChatTools(Chat $chat): void
    {
        $this->toolRegistry->register(new Tools\CreateArtifactTool($chat, $this->artifactStateService));
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
     * @param User        $user    Authenticated application user
     * @param Collection  $history Recent chat history (ChatMessage / any model with role+content)
     * @param string      $content The new user message
     * @param string|null $channel Channel name for memory resolution (e.g. 'telegram'). Null = web.
     * @param OutputMode  $mode    Output formatting mode: md (Markdown) or plain text.
     */
    public function processMessage(User $user, Collection $history, string $content, ?string $channel = null, OutputMode $mode = OutputMode::PLAIN): string
    {
        $this->clearStopFlag($user->id);

        // Register user-specific tools (always needed regardless of channel)
        $this->toolRegistry->register(new Tools\UpdateMemoryTool($user, $channel ?? 'web'));

        // Register context-free tools (available for all channels)
        $this->toolRegistry->register(new Tools\GetUserInfoTool);
        $this->toolRegistry->register(new Tools\SearchMeetingsTool);
        $this->toolRegistry->register(new Tools\GetMeetingSummaryTool);
        $this->toolRegistry->register(new Tools\GetMeetingTasksTool);
        $this->toolRegistry->register(new Tools\GetFollowupTool);
        $this->toolRegistry->register(new Tools\GetExtractedFactsTool);
        $this->toolRegistry->register(new Tools\GetUserInsightsTool);
        $this->toolRegistry->register(new Tools\GetInsightProfileHistoryTool);
        $this->toolRegistry->register(new Tools\GetTeamMembersTool);
        $this->toolRegistry->register(new Tools\GetRelationshipInsightTool);
        $this->toolRegistry->register(new Tools\GetUserShortTermMemoryTool);

        // Load memory context
        $memoryContext = $this->memoryService->composeMemoryContext($user, $channel);

        // Prepare system prompt
        $systemPrompt = $this->getSystemPrompt($memoryContext, $user, $mode);

        // Inject current date/time into user message so the model reliably knows the date
        $now = now()->timezone('Europe/Moscow');
        $datePrefix = "[Current date: {$now->format('Y-m-d')}, time: {$now->format('H:i')} MSK]";

        // Inject current user identity so the model can't miss it
        $userId    = $user->id;
        $profileId = Profile::where('user_id', $userId)->value('id');
        $userName  = $user->name ?? 'Unknown';
        $userPrefix = $userName && $profileId
            ? "[Sender: {$userName} (user_id={$userId}, profile_id={$profileId})]"
            : '';

        // Build messages from history + current message
        $messages = [];
        foreach ($history as $msg) {
            $messages[] = [
                'role'    => $msg->role === 'user' ? 'user' : 'assistant',
                'content' => $msg->content,
            ];
        }
        $messages[] = [
            'role'    => 'user',
            'content' => trim("{$datePrefix} {$userPrefix}") . "\n\n{$content}",
        ];

        // Get available tools
        $tools = $this->toolRegistry->getToolsForLLM();

        $iteration = 0;
        $finalAnswer = null;

        Log::info('Agent loop started', [
            'user_id'              => $user->id,
            'channel'              => $channel,
            'message'              => $content,
            'tools_count'          => count($tools),
            'system_prompt_length' => strlen($systemPrompt),
        ]);

        // Agent loop
        while ($iteration < self::MAX_ITERATIONS) {
            // Check if stop was requested
            if ($this->isStopRequested($user->id)) {
                Log::info('Agent loop stopped by user request', [
                    'user_id'   => $user->id,
                    'iteration' => $iteration,
                ]);
                $this->clearStopFlag($user->id);

                return '⛔️ Processing stopped by your request.';
            }

            $iteration++;

            Log::info('Agent loop iteration', [
                'iteration'      => $iteration,
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
                        $toolName   = $toolCall['function']['name'] ?? null;
                        $toolArgs   = json_decode($toolCall['function']['arguments'] ?? '{}', true) ?? [];
                        $toolCallId = $toolCall['id'] ?? 'unknown';

                        Log::info('Executing tool', [
                            'tool' => $toolName,
                            'args' => $toolArgs,
                        ]);

                        $tool = $this->toolRegistry->get($toolName);

                        if (! $tool) {
                            $toolResult = [
                                'success' => false,
                                'error'   => "Tool '{$toolName}' not found",
                            ];
                        } else {
                            try {
                                $toolResult = $tool->execute($toolArgs);
                            } catch (\Exception $e) {
                                Log::error('Tool execution failed', [
                                    'tool'  => $toolName,
                                    'error' => $e->getMessage(),
                                ]);

                                $toolResult = [
                                    'success' => false,
                                    'error'   => $e->getMessage(),
                                ];
                            }
                        }

                        // Add tool result to messages (with truncation for large results)
                        $messages[] = [
                            'role'         => 'tool',
                            'tool_call_id' => $toolCallId,
                            'content'      => $this->truncateToolResult($toolResult, $toolName),
                        ];

                        // Validate tool result and add feedback if issues detected (Pattern 3)
                        $validation = $this->validateToolResult($toolResult, $toolName, $toolArgs);
                        if ($validation['requires_reflection']) {
                            $this->addValidationFeedback($messages, $validation, $toolName);

                            Log::info('Tool validation detected issues', [
                                'tool'   => $toolName,
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
                    'error'     => $e->getMessage(),
                    'trace'     => $e->getTraceAsString(),
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
            'iterations'          => $iteration,
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
            'tool'          => $toolName,
            'original_size' => strlen($encoded),
            'max_size'      => self::MAX_TOOL_RESULT_CHARS,
        ]);

        // For transcript tool — keep metadata, truncate entries
        if ($toolName === 'get_transcript' && is_array($toolResult) && isset($toolResult['transcript'])) {
            $entries      = $toolResult['transcript'];
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
                $toolResult['_truncated']      = true;
                $toolResult['_total_entries']  = $totalEntries;
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
                    '_masked'        => true,
                    '_note'          => 'Previous tool output omitted for brevity. Result was processed in earlier iteration.',
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
        $warningLimit    = (int) (self::MODEL_CONTEXT_LIMIT * self::TOKEN_BUDGET_WARNING_THRESHOLD);
        $criticalLimit   = (int) (self::MODEL_CONTEXT_LIMIT * self::TOKEN_BUDGET_CRITICAL_THRESHOLD);

        if ($estimatedTokens > $criticalLimit) {
            // Last resort: mask everything except the very last tool result
            $this->maskOldToolResults($messages, 1);
            $estimatedTokens = $this->estimateTokens($messages, $systemPrompt);

            if ($estimatedTokens > $criticalLimit) {
                Log::warning('Token budget critical — forcing loop end', [
                    'estimated_tokens' => $estimatedTokens,
                    'critical_limit'   => $criticalLimit,
                ]);

                return false;
            }
        } elseif ($estimatedTokens > $warningLimit) {
            Log::info('Token budget warning — masking old tool results', [
                'estimated_tokens' => $estimatedTokens,
                'warning_limit'    => $warningLimit,
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
            $error    = $toolResult['error'] ?? 'Unknown error';
            $issues[] = "Tool returned explicit failure: {$error}";

            // Provide hints based on tool type
            if ($toolName === 'search_meetings' && str_contains($error, 'not found')) {
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
                    if ($toolName === 'search_meetings') {
                        $issues[] = 'Hint: Empty results might mean wrong user_id, date range, or the data truly doesn\'t exist. Consider verifying parameters.';
                    }
                }
            }
        }

        // Check 3: Missing expected fields
        $expectedFieldsByTool = [
            'get_transcript'  => ['event', 'transcript'],
            'search_meetings' => ['success'],
            'get_user_info'   => ['success', 'user'],
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
            'valid'               => empty($issues),
            'issues'              => $issues,
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
            'tool'         => $toolName,
            'issues_count' => count($validation['issues']),
        ]);

        // Enrich the last tool message with validation notes
        $lastMessageIndex = count($messages) - 1;
        if ($lastMessageIndex >= 0 && isset($messages[$lastMessageIndex]['role']) && $messages[$lastMessageIndex]['role'] === 'tool') {
            $originalContent = $messages[$lastMessageIndex]['content'];
            $decoded         = json_decode($originalContent, true);

            if (is_array($decoded)) {
                $decoded['_validation_feedback']        = $feedbackContent;
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
        $lookbackLimit   = 6; // Check last 6 tool calls

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
            $signature        = $call['tool'].'::'.md5($call['args']);
            $callSignatures[] = $signature;
        }

        // Count occurrences
        $signatureCounts = array_count_values($callSignatures);
        $maxRepetitions  = max($signatureCounts);

        if ($maxRepetitions >= 3) {
            // Same call repeated 3+ times
            $repeatedSignature = array_search($maxRepetitions, $signatureCounts);
            $repeatedCall      = null;

            foreach ($recentToolCalls as $call) {
                if ($call['tool'].'::'.md5($call['args']) === $repeatedSignature) {
                    $repeatedCall = $call;
                    break;
                }
            }

            return [
                'stuck'       => true,
                'pattern'     => 'exact_repetition',
                'tool'        => $repeatedCall['tool'] ?? 'unknown',
                'repetitions' => $maxRepetitions,
                'suggestion'  => "The tool '{$repeatedCall['tool']}' has been called {$maxRepetitions} times with the same parameters. This suggests you're stuck in a loop. Try a completely different approach or tool.",
            ];
        }

        // Check for same tool different args (strategy not working)
        $toolNames            = array_map(fn ($call) => $call['tool'], $recentToolCalls);
        $toolCounts           = array_count_values($toolNames);
        $maxToolRepetitions   = max($toolCounts);

        if ($maxToolRepetitions >= 4) {
            $repeatedTool = array_search($maxToolRepetitions, $toolCounts);

            return [
                'stuck'       => true,
                'pattern'     => 'same_tool_different_params',
                'tool'        => $repeatedTool,
                'repetitions' => $maxToolRepetitions,
                'suggestion'  => "The tool '{$repeatedTool}' has been tried {$maxToolRepetitions} times with different parameters but still not working. Consider using a different tool or asking the user for clarification.",
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
            'pattern'     => $detection['pattern'],
            'tool'        => $detection['tool'],
            'repetitions' => $detection['repetitions'],
        ]);

        // Add warning to last tool message
        $lastMessageIndex = count($messages) - 1;
        if ($lastMessageIndex >= 0 && isset($messages[$lastMessageIndex]['role']) && $messages[$lastMessageIndex]['role'] === 'tool') {
            $originalContent = $messages[$lastMessageIndex]['content'];
            $decoded         = json_decode($originalContent, true);

            if (is_array($decoded)) {
                $decoded['_repetition_warning']         = "🔄 REPETITION DETECTED: {$detection['suggestion']}";
                $messages[$lastMessageIndex]['content'] = json_encode($decoded);
            }
        }
    }

    private function getSystemPrompt(string $memoryContext, User $user, OutputMode $mode = OutputMode::PLAIN): string
    {
        $now         = now()->timezone('Europe/Moscow');
        $currentDate = $now->translatedFormat('l, d F Y');
        $currentTime = $now->format('H:i');

        $userName  = $user->name ?? 'Unknown';
        $userId    = $user->id;
        $profileId = Profile::where('user_id', $userId)->value('id');

        $profileHint        = $profileId ? ", profile_id={$profileId}" : '';
        $currentUserContext = "## Current User\n\nThe person sending you messages is **{$userName}** (user_id={$userId}{$profileHint}).\n\nWhen the user says \"me\", \"I\", \"мне\", \"обо мне\", \"мой профиль\" — they are referring to {$userName} (profile_id={$profileId}).\n\nRules:\n- Do NOT call get_user_info for {$userName} — their IDs are already known: user_id={$userId}, profile_id={$profileId}\n- When asked about their profile/insights → call get_user_insights(profile_id={$profileId}) directly\n- If you see \"{$userName}\" in meeting participants — that IS this person, no need to look them up\n";

        $dbSchema = $this->databaseSchemaService->getSchemaForAgent();
        $databaseSchemaSection = "## Database Schema\n\nThe following tables and columns are available for `execute_sql_query`:\n\n```\n{$dbSchema}\n```\n\n## When No Specialized Tool Fits\n\nIf none of the specialized tools can answer the question, use `execute_sql_query` as a universal fallback:\n- It accepts any read-only SELECT query joining any number of tables\n- Use ILIKE for case-insensitive text search (e.g. `WHERE name ILIKE '%backenders%'`)\n- Use JOINs to aggregate data from multiple tables in a single request instead of chaining multiple tool calls\n- Always include `__ACCESSIBLE_USER_IDS__` in WHERE when querying `followups`, `sources`, or `calendar_events`\n\nExamples of questions best answered with SQL:\n- \"How many meetings did each team member attend last month?\"\n- \"Which users have no profile yet?\"\n- \"Show tasks assigned to the backend team\"\n";

        $formattingInstructions = $mode === OutputMode::MD
            ? "## Output Format\n\nFormat your responses using **Markdown**: use headings, bullet lists, bold, italic, and code blocks where appropriate. Do NOT use plain prose when structured formatting improves readability."
            : "## Output Format\n\nReturn plain text only. Do NOT use Markdown syntax (no **, no ##, no backticks, no bullet dashes). Write in clear, readable prose.";

        return <<<PROMPT
You are a helpful AI assistant integrated with a Telegram bot. You have access to various tools to help answer user questions.

## Current Date and Time

Today is {$currentDate}, {$currentTime} (MSK, Moscow Time, UTC+3).

{$currentUserContext}
{$memoryContext}

{$databaseSchemaSection}

## Your Capabilities

You have access to various tools that allow you to:
- Retrieve and analyze data from the platform
- Access user information, teams, meetings, and transcripts
- Execute database queries to get specific information
- Manage conversation history and memory about users

## Tool Usage Priority - CRITICAL

When asked about a **meeting**, choose the right tool:
- **`get_meeting_summary`** — AI-generated summary: what was discussed, key points, decisions. Use for "what was discussed?", "what did they decide?", "summarize the Friday meeting". **This tool alone is sufficient — do NOT additionally call get_meeting_tasks unless the user explicitly asked about tasks.**
- **`get_meeting_tasks`** — action items and assignments from a meeting. Use for "what tasks were created?", "who was assigned what?", "any open tasks from the planning?". **Only call this if the user explicitly asked about tasks or action items.**
- **`get_followup`** — AI-generated assessment reports for meeting participants. Use for "what was the followup for Ivan?", "show evaluation results from the meeting".
- **`get_extracted_facts`** — raw facts about specific participants from that meeting. Use for "what did we learn about Ivan at that meeting?" (requires profile_id from get_user_info).

**STOP after you have enough data to answer.** Do not call extra tools "just in case". If get_meeting_summary answers the question — answer immediately without calling get_meeting_tasks.

## IDs — CRITICAL RULES

**NEVER guess or invent IDs.** Only use IDs that were explicitly returned by a previous tool call in this conversation.

**profile_id workflow:**
1. `get_meeting_summary` returns `participants` as objects: `{"name": "...", "profile_id": N}` — **use these profile_ids directly**, no need to call `get_user_info` for each participant
2. If a participant has no `profile_id` in the summary, only then call `get_user_info` by name to resolve it
3. Use `profile_id` in subsequent calls to `get_extracted_facts`, `get_user_insights`, `get_insight_profile_history`
4. **Do NOT call `get_user_info` for a person whose profile_id you already have**
5. **Do NOT use user_id as profile_id** — they are different numbers

**When processing multiple people:**
- Get participant profile_ids directly from `get_meeting_summary` response — they are already there
- Participants listed in meeting summary are real people — project/product names (like "Wanda") are NOT people, do not search for them
- Call `get_user_insights(profile_id=...)` for each participant directly, without intermediate `get_user_info` calls

When asked about a **person**, choose the right tool:
- **`get_user_insights`** — aggregated long-term profile. Use for "who is Ivan?", "describe Ivan's strengths".
- **`get_extracted_facts`** — source-specific facts. Use for "what did we learn about Ivan from transcripts?".
- **`get_insight_profile_history`** — version history of a person's profile. Use for "how has Ivan changed?", "show evolution of communication style" (requires profile_id from get_user_info).

**`get_transcript` is LAST RESORT** — only when:
   - Summary/facts are insufficient for the user's question
   - User explicitly asks for verbatim conversation details
   - You need to verify exact quotes or specific dialogue

**IMPORTANT**: Before using `get_transcript`, you MUST:
- Explain to the user why you need the full transcript
- Ask for explicit permission
- Only proceed if user confirms

Example:
❌ BAD: Immediately calling get_transcript when user asks about a meeting
✅ GOOD: First try get_meeting_summary, then if more detail needed ask "May I access the full transcript?"

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

## Reflection and Self-Checking - CRITICAL

After executing ANY tool, you MUST verify the result before proceeding:

**Questions to ask yourself:**
1. Did the tool execute successfully?
2. Does the result make logical sense?
3. Is the result complete and useful for answering the user's question?
4. Are there any contradictions or inconsistencies?
5. Do I have enough information, or do I need more?

**If something seems wrong:**
- Don't just accept the result — investigate why it failed or returned unexpected data
- Consider alternative approaches: different tool, different parameters, different strategy
- If a tool returns empty results, ask yourself: "Is this because there's truly no data, or did I use wrong parameters?"
- If a tool fails, ask yourself: "Why did it fail? What can I do differently?"

**Example - Good Reflection:**
Tool: search_meetings(user_id=123) → Returns: []

❌ BAD: "No meetings found."

✅ GOOD: "Empty result. This is unusual. Let me verify:
- Is user_id=123 correct? Let me check with get_user_info first.
- Maybe the date range is wrong? Let me try a broader search.
- Maybe there are meetings but they're filtered out?"

**Self-Correction Pattern:**
1. Execute tool
2. Check result quality
3. If something is off → investigate and retry with corrections
4. Only proceed when confident the result is correct

## Guidelines

- Use tools when you need specific information to answer questions
- ALWAYS check your memory at the start and follow preferences stored there
- Adapt your communication style based on what you know about the user
- When you use tools, explain what information you found
- **CRITICAL**: Update your memory whenever you learn something important about the user
- **CRITICAL**: Always verify tool results before trusting them

{$formattingInstructions}

PROMPT;
    }
}
