<?php

namespace Tests\Feature;

use App\Models\AgentMemory;
use App\Models\AgentProfile;
use App\Models\AgentTask;
use App\Models\ChatMessage;
use App\Models\Setting;
use App\Models\User;
use App\Services\Agent\AgentService;
use App\Services\Agent\Tools\ToolInterface;
use App\Services\Agent\Tools\ToolRegistry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Тесты для AgentService::processMessage()
 * Метод принимает User, историю сообщений и текст — возвращает строку-ответ.
 */
class ChatAgentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected bool $mockLlm = false;

    protected User $user;

    protected ToolRegistry $toolRegistry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        // Чистый реестр без реальных инструментов, чтобы не делать реальных запросов
        $this->toolRegistry = new ToolRegistry;
        $this->app->instance(ToolRegistry::class, $this->toolRegistry);
    }

    #[Test]
    public function it_returns_llm_response_as_string(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response($this->makeTextResponse('Ответ на вопрос'), 200),
        ]);

        $result = $this->makeService()->processMessage(
            $this->user,
            new Collection,
            'Вопрос пользователя'
        );

        $this->assertEquals('Ответ на вопрос', $result);
    }

    #[Test]
    public function it_sends_current_message_to_llm(): void
    {
        $capturedMessages = null;

        Http::fake(function ($request) use (&$capturedMessages) {
            $capturedMessages = $request->data()['messages'] ?? null;

            return Http::response($this->makeTextResponse('OK'), 200);
        });

        $this->makeService()->processMessage(
            $this->user,
            new Collection,
            'Текущий вопрос'
        );

        $this->assertNotNull($capturedMessages);
        $userMessages = array_filter($capturedMessages, fn ($m) => $m['role'] === 'user');
        $lastUser = array_values(array_reverse($userMessages))[0] ?? null;

        $this->assertNotNull($lastUser);
        $this->assertStringContainsString('Текущий вопрос', $lastUser['content']);
    }

    #[Test]
    public function it_includes_history_in_llm_messages(): void
    {
        $history = new Collection([
            $this->makeHistoryMessage('user', 'Предыдущий вопрос'),
            $this->makeHistoryMessage('assistant', 'Предыдущий ответ'),
        ]);

        $capturedMessages = null;

        Http::fake(function ($request) use (&$capturedMessages) {
            $capturedMessages = $request->data()['messages'] ?? null;

            return Http::response($this->makeTextResponse('Response'), 200);
        });

        $this->makeService()->processMessage($this->user, $history, 'Новый вопрос');

        $this->assertNotNull($capturedMessages);
        $this->assertCount(3, $capturedMessages); // 2 история + 1 новое
        $this->assertEquals('Предыдущий вопрос', $capturedMessages[0]['content']);
        $this->assertEquals('Предыдущий ответ', $capturedMessages[1]['content']);
        $this->assertStringContainsString('Новый вопрос', $capturedMessages[2]['content']);
    }

    #[Test]
    public function it_sends_system_prompt_to_llm(): void
    {
        $capturedSystem = null;

        Http::fake(function ($request) use (&$capturedSystem) {
            $capturedSystem = $request->data()['system'] ?? null;

            return Http::response($this->makeTextResponse('OK'), 200);
        });

        $this->makeService()->processMessage($this->user, new Collection, 'Question');

        $this->assertNotNull($capturedSystem);
        $this->assertNotEmpty($capturedSystem);
    }

    #[Test]
    public function it_uses_model_router_for_interactive_runs(): void
    {
        Setting::set('model.interactive', 'test/router-model');

        $capturedModel = null;

        Http::fake(function ($request) use (&$capturedModel) {
            $capturedModel = $request->data()['model'] ?? null;

            return Http::response($this->makeTextResponse('OK'), 200);
        });

        $this->makeService()->processMessage($this->user, new Collection, 'Question');

        $this->assertSame('test/router-model', $capturedModel);
    }

    #[Test]
    public function it_compacts_old_history_into_system_prompt(): void
    {
        config()->set('agent.compaction.keep_recent_messages', 2);

        $history = new Collection([
            $this->makeHistoryMessage('user', 'Question 1'),
            $this->makeHistoryMessage('assistant', 'Answer 1'),
            $this->makeHistoryMessage('user', 'Question 2'),
            $this->makeHistoryMessage('assistant', 'Answer 2'),
            $this->makeHistoryMessage('user', 'Question 3'),
        ]);

        $capturedMessages = null;
        $capturedSystem = null;

        Http::fake(function ($request) use (&$capturedMessages, &$capturedSystem) {
            $capturedMessages = $request->data()['messages'] ?? null;
            $capturedSystem = $request->data()['system'] ?? null;

            return Http::response($this->makeTextResponse('OK'), 200);
        });

        $this->makeService()->processMessage($this->user, $history, 'New question');

        $this->assertCount(3, $capturedMessages);
        $this->assertStringContainsString('Earlier conversation summary', $capturedSystem);
        $this->assertStringContainsString('Question 1', $capturedSystem);
        $this->assertEquals('Answer 2', $capturedMessages[0]['content']);
        $this->assertEquals('Question 3', $capturedMessages[1]['content']);
    }

    #[Test]
    public function it_handles_tool_calls_and_returns_final_answer(): void
    {
        $fakeTool = $this->createMockTool('test_tool', ['result' => 'данные из инструмента']);
        $this->toolRegistry->register($fakeTool);

        $callCount = 0;

        Http::fake(function ($request) use (&$callCount) {
            $callCount++;

            if ($callCount === 1) {
                return Http::response($this->makeToolCallResponse('test_tool', ['param' => 'val']), 200);
            }

            return Http::response($this->makeTextResponse('Финальный ответ'), 200);
        });

        $result = $this->makeService()->processMessage(
            $this->user,
            new Collection,
            'Запусти инструмент'
        );

        $this->assertEquals('Финальный ответ', $result);
        $this->assertEquals(2, $callCount);
    }

    #[Test]
    public function it_passes_tool_result_to_llm_on_next_iteration(): void
    {
        $fakeTool = $this->createMockTool('my_tool', ['data' => 'важные данные']);
        $this->toolRegistry->register($fakeTool);

        $secondCallMessages = null;

        Http::fake(function ($request) use (&$secondCallMessages) {
            static $count = 0;
            $count++;

            if ($count === 1) {
                return Http::response($this->makeToolCallResponse('my_tool', [], 'call_abc'), 200);
            }

            $secondCallMessages = $request->data()['messages'] ?? null;

            return Http::response($this->makeTextResponse('Done'), 200);
        });

        $this->makeService()->processMessage($this->user, new Collection, 'Use tool');

        $this->assertNotNull($secondCallMessages);
        $toolMessages = array_filter($secondCallMessages, fn ($m) => ($m['role'] ?? '') === 'tool');
        $this->assertNotEmpty($toolMessages);

        $toolMessage = array_values($toolMessages)[0];
        $this->assertEquals('call_abc', $toolMessage['tool_call_id']);

        $decoded = json_decode($toolMessage['content'], true);
        $this->assertEquals('важные данные', $decoded['data'] ?? null);
    }

    #[Test]
    public function it_handles_unknown_tool_gracefully(): void
    {
        // Никаких инструментов не зарегистрировано

        Http::fake([
            'openrouter.ai/*' => Http::sequence()
                ->push($this->makeToolCallResponse('nonexistent_tool', []), 200)
                ->push($this->makeTextResponse('Ответ после ошибки инструмента'), 200),
        ]);

        $result = $this->makeService()->processMessage(
            $this->user,
            new Collection,
            'Use unknown tool'
        );

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    #[Test]
    public function it_can_read_agent_memories_via_tool_calls(): void
    {
        $profile = AgentProfile::create([
            'key' => 'github-reviewer',
            'name' => 'GitHub Reviewer',
        ]);

        AgentTask::create([
            'user_id' => $this->user->id,
            'agent_profile_id' => $profile->id,
            'name' => 'Scan repository',
            'prompt' => 'Scan repository',
            'schedule_type' => 'one_off',
            'next_run_at' => now(),
            'input_payload' => [
                'provider' => 'github',
                'owner' => 'acme',
                'repo' => 'api',
            ],
        ]);

        AgentMemory::create([
            'agent_profile_id' => $profile->id,
            'scope_type' => 'repository',
            'scope_key' => 'github:acme/api',
            'kind' => 'architecture_fact',
            'content' => 'Repository uses layered architecture with services and repositories.',
            'priority' => 90,
            'active' => true,
            'last_seen_at' => now(),
        ]);

        Http::fake([
            'openrouter.ai/*' => Http::sequence()
                ->push($this->makeToolCallResponse('search_agent_memories', [
                    'profile_key' => 'github-reviewer',
                    'provider' => 'github',
                    'owner' => 'acme',
                    'repo' => 'api',
                ]), 200)
                ->push($this->makeTextResponse('В памяти агента есть layered architecture с services и repositories.'), 200),
        ]);

        $result = $this->makeService()->processMessage(
            $this->user,
            new Collection,
            'Что агент уже знает про репозиторий acme/api?'
        );

        $this->assertStringContainsString('layered architecture', $result);
    }

    #[Test]
    public function it_handles_llm_http_error_gracefully(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response(['error' => 'Server Error'], 500),
        ]);

        $result = $this->makeService()->processMessage(
            $this->user,
            new Collection,
            'Question'
        );

        // Не должно бросать исключение, возвращает строку с ошибкой
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    #[Test]
    public function it_appends_page_context_to_the_current_message_only(): void
    {
        $capturedMessages = null;

        Http::fake(function ($request) use (&$capturedMessages) {
            $capturedMessages = $request->data()['messages'] ?? null;

            return Http::response($this->makeTextResponse('OK'), 200);
        });

        $result = $this->makeService()->run(
            $this->user,
            new Collection([
                $this->makeHistoryMessage('user', 'Предыдущий вопрос'),
                $this->makeHistoryMessage('assistant', 'Предыдущий ответ'),
            ]),
            'Текущий вопрос',
            new \App\Services\Agent\AgentRunOptions(
                taskType: \App\Enums\AgentTaskType::INTERACTIVE,
            ),
            [
                'page_context' => [
                    'title' => 'Dashboard',
                    'url' => 'https://app.example.com/dashboard',
                    'text' => 'Dashboard Open issues',
                ],
            ]
        );

        $this->assertSame('OK', $result);
        $this->assertNotNull($capturedMessages);
        $this->assertCount(3, $capturedMessages);
        $this->assertSame('Предыдущий вопрос', $capturedMessages[0]['content']);
        $this->assertSame('Предыдущий ответ', $capturedMessages[1]['content']);
        $this->assertStringContainsString('Текущий вопрос', $capturedMessages[2]['content']);
        $this->assertStringContainsString('Page title: Dashboard', $capturedMessages[2]['content']);
        $this->assertStringContainsString('Open issues', $capturedMessages[2]['content']);
    }

    // --- Вспомогательные методы ---

    private function makeService(): AgentService
    {
        return $this->app->make(AgentService::class);
    }

    private function makeHistoryMessage(string $role, string $content): ChatMessage
    {
        $message = new ChatMessage;
        $message->role = $role;
        $message->content = $content;

        return $message;
    }

    private function makeTextResponse(string $content): array
    {
        return [
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => $content,
                ],
                'finish_reason' => 'stop',
            ]],
        ];
    }

    private function makeToolCallResponse(string $toolName, array $args, string $callId = 'call_test_123'): array
    {
        return [
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => $callId,
                        'type' => 'function',
                        'function' => [
                            'name' => $toolName,
                            'arguments' => json_encode($args),
                        ],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
        ];
    }

    private function createMockTool(string $name, mixed $result): ToolInterface
    {
        return new class($name, $result) implements ToolInterface
        {
            public function __construct(
                private readonly string $toolName,
                private readonly mixed $toolResult,
            ) {}

            public function getName(): string
            {
                return $this->toolName;
            }

            public function getDescription(): string
            {
                return 'Mock tool for testing';
            }

            public function getParameters(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(?array $parameters): mixed
            {
                return $this->toolResult;
            }
        };
    }

    /** Build a response with multiple parallel tool calls in one assistant message */
    private function makeParallelToolCallResponse(array $tools): array
    {
        $toolCalls = [];
        foreach ($tools as $i => [$name, $args]) {
            $toolCalls[] = [
                'id'       => "call_{$name}_{$i}",
                'type'     => 'function',
                'function' => [
                    'name'      => $name,
                    'arguments' => json_encode($args),
                ],
            ];
        }

        return [
            'choices' => [[
                'message' => [
                    'role'       => 'assistant',
                    'content'    => null,
                    'tool_calls' => $toolCalls,
                ],
                'finish_reason' => 'tool_calls',
            ]],
        ];
    }

    // --- Change B: Batch-aware masking tests ---

    #[Test]
    public function it_keeps_all_parallel_tool_results_from_last_iteration(): void
    {
        // Iteration 1: 1 tool call (team lookup)
        // Iteration 2: 5 parallel tool calls (user insights)
        // Iteration 3: LLM synthesizes — assert all 5 iter-2 results are visible (not masked)

        config()->set('agent.in_run_masking.keep_recent_iterations', 2);

        $tool = $this->createMockTool('insight_tool', ['user_name' => 'slava', 'profile_id' => 5]);
        $this->toolRegistry->register($tool);
        $this->toolRegistry->register($this->createMockTool('team_tool', ['members' => [1, 2, 3, 4, 5]]));

        $thirdCallMessages = null;
        $callCount = 0;

        Http::fake(function ($request) use (&$thirdCallMessages, &$callCount) {
            $callCount++;

            // Iteration 1: call one team tool
            if ($callCount === 1) {
                return Http::response($this->makeToolCallResponse('team_tool', [], 'call_team'), 200);
            }

            // Iteration 2: call 5 parallel insight tools
            if ($callCount === 2) {
                return Http::response($this->makeParallelToolCallResponse([
                    ['insight_tool', ['profile_id' => 1]],
                    ['insight_tool', ['profile_id' => 2]],
                    ['insight_tool', ['profile_id' => 3]],
                    ['insight_tool', ['profile_id' => 4]],
                    ['insight_tool', ['profile_id' => 5]],
                ]), 200);
            }

            // Iteration 3: capture messages for assertion
            $thirdCallMessages = $request->data()['messages'] ?? [];

            return Http::response($this->makeTextResponse('Финальный ответ'), 200);
        });

        $result = $this->makeService()->processMessage($this->user, new Collection, 'Состав команды');

        $this->assertEquals('Финальный ответ', $result);
        $this->assertEquals(3, $callCount);

        // All 5 parallel insight results from iteration 2 must be visible (not masked)
        $toolMessages = array_filter($thirdCallMessages, fn ($m) => ($m['role'] ?? '') === 'tool');
        $this->assertCount(6, $toolMessages, 'Expected 6 tool messages: 1 from iter 1 + 5 from iter 2');

        $maskedCount = 0;
        foreach ($toolMessages as $msg) {
            $decoded = json_decode($msg['content'], true);
            if (is_array($decoded) && ($decoded['_masked'] ?? false)) {
                $maskedCount++;
            }
        }

        // With keepRecentIterations=2, iterations 1 and 2 are both kept → 0 masked
        $this->assertEquals(0, $maskedCount, 'No tool results should be masked with keepRecentIterations=2');
    }

    #[Test]
    public function it_masks_old_iteration_results_beyond_keep_window(): void
    {
        // With keepRecentIterations=1: only the last iteration's results are kept
        // Iteration 1: 1 tool result
        // Iteration 2: 2 parallel tool results
        // Iteration 3: LLM call — iter 1 result should be masked, iter 2 results visible

        config()->set('agent.in_run_masking.keep_recent_iterations', 1);

        // Results must be > 200 chars to trigger masking (the size guard in maskOldToolResults)
        $largeResult = ['data' => str_repeat('x', 250)];

        $this->toolRegistry->register($this->createMockTool('tool_a', $largeResult));
        $this->toolRegistry->register($this->createMockTool('tool_b', $largeResult));

        $thirdCallMessages = null;
        $callCount = 0;

        Http::fake(function ($request) use (&$thirdCallMessages, &$callCount) {
            $callCount++;

            if ($callCount === 1) {
                return Http::response($this->makeToolCallResponse('tool_a', [], 'call_a1'), 200);
            }

            if ($callCount === 2) {
                return Http::response($this->makeParallelToolCallResponse([
                    ['tool_b', ['x' => 1]],
                    ['tool_b', ['x' => 2]],
                ]), 200);
            }

            $thirdCallMessages = $request->data()['messages'] ?? [];

            return Http::response($this->makeTextResponse('Done'), 200);
        });

        $this->makeService()->processMessage($this->user, new Collection, 'Run tools');

        $toolMessages = array_filter($thirdCallMessages ?? [], fn ($m) => ($m['role'] ?? '') === 'tool');
        $this->assertCount(3, $toolMessages, '1 from iter 1 + 2 from iter 2');

        $contents = array_map(fn ($m) => json_decode($m['content'], true), array_values($toolMessages));

        // First tool result (iter 1) should be masked
        $this->assertTrue($contents[0]['_masked'] ?? false, 'Iteration 1 result should be masked');

        // Last two tool results (iter 2) should be visible
        $this->assertFalse($contents[1]['_masked'] ?? false, 'Iteration 2 result 1 should be visible');
        $this->assertFalse($contents[2]['_masked'] ?? false, 'Iteration 2 result 2 should be visible');
    }

    // --- Change C: In-run LLM compaction tests ---

    #[Test]
    public function it_does_not_trigger_compaction_when_disabled(): void
    {
        config()->set('agent.in_run_compaction.enabled', false);
        config()->set('agent.in_run_compaction.threshold', 1); // Would fire immediately if enabled

        $this->toolRegistry->register($this->createMockTool('some_tool', ['ok' => true]));

        $llmCallCount = 0;

        Http::fake(function ($request) use (&$llmCallCount) {
            $llmCallCount++;

            if ($llmCallCount === 1) {
                return Http::response($this->makeToolCallResponse('some_tool', []), 200);
            }

            return Http::response($this->makeTextResponse('Answer'), 200);
        });

        $this->makeService()->processMessage($this->user, new Collection, 'Question');

        // Only 2 LLM calls: 1 for tool call, 1 for final answer. No compaction call.
        $this->assertEquals(2, $llmCallCount, 'No extra LLM call should be made when compaction is disabled');
    }

    #[Test]
    public function it_triggers_compaction_and_injects_in_run_memory(): void
    {
        config()->set('agent.in_run_compaction.enabled', true);
        config()->set('agent.in_run_compaction.threshold', 2); // Fire at iteration 2

        $this->toolRegistry->register($this->createMockTool('data_tool', ['info' => 'key facts']));

        $capturedSystem = null;

        Http::fake(function ($request) use (&$capturedSystem) {
            $data = $request->data();

            // Compaction call: no 'tools' key (uses chat(), not chatWithTools())
            if (! isset($data['tools'])) {
                return Http::response($this->makeTextResponse('{"people": [], "current_task": "test"}'), 200);
            }

            // Main loop iter 1: return tool call
            $hasToolMessages = collect($data['messages'] ?? [])->contains(fn ($m) => ($m['role'] ?? '') === 'tool');

            if (! $hasToolMessages) {
                return Http::response($this->makeToolCallResponse('data_tool', []), 200);
            }

            // Main loop iter 2 (after compaction): capture system prompt, return final answer
            $capturedSystem = $data['system'] ?? null;

            return Http::response($this->makeTextResponse('Done'), 200);
        });

        $result = $this->makeService()->processMessage($this->user, new Collection, 'Question');

        $this->assertEquals('Done', $result);
        $this->assertNotNull($capturedSystem);
        $this->assertStringContainsString('<in_run_memory>', $capturedSystem, 'System prompt should contain in_run_memory block');
    }

    #[Test]
    public function it_triggers_compaction_only_once_even_past_threshold(): void
    {
        config()->set('agent.in_run_compaction.enabled', true);
        config()->set('agent.in_run_compaction.threshold', 2);

        $this->toolRegistry->register($this->createMockTool('tool_x', ['v' => 1]));

        $compactionCallCount = 0;
        $llmCallCount = 0;

        Http::fake(function ($request) use (&$compactionCallCount, &$llmCallCount) {
            $llmCallCount++;
            $data = $request->data();

            // Detect compaction call by: it's a non-streaming chat call to extraction model
            // or just count all calls and subtract expected main calls
            // Simplest: compaction uses 'openai/gpt-4.1-mini', main loop uses configured interactive model
            if (($data['model'] ?? '') === 'openai/gpt-4.1-mini') {
                $compactionCallCount++;

                return Http::response('{"people": [], "current_task": "test"}', 200);
            }

            // Iter 1: tool call
            if ($llmCallCount <= 2) {
                return Http::response($this->makeToolCallResponse('tool_x', []), 200);
            }

            return Http::response($this->makeTextResponse('Final'), 200);
        });

        $this->makeService()->processMessage($this->user, new Collection, 'Run multi-iteration');

        $this->assertEquals(1, $compactionCallCount, 'Compaction should fire exactly once');
    }

    #[Test]
    public function it_continues_after_compaction_failure(): void
    {
        config()->set('agent.in_run_compaction.enabled', true);
        config()->set('agent.in_run_compaction.threshold', 2);

        $this->toolRegistry->register($this->createMockTool('tool_y', ['v' => 1]));

        $llmCallCount = 0;

        Http::fake(function ($request) use (&$llmCallCount) {
            $llmCallCount++;
            $data = $request->data();

            // Compaction model call fails
            if (($data['model'] ?? '') === 'openai/gpt-4.1-mini') {
                return Http::response(['error' => 'Internal Server Error'], 500);
            }

            if ($llmCallCount === 1) {
                return Http::response($this->makeToolCallResponse('tool_y', []), 200);
            }

            return Http::response($this->makeTextResponse('Survived'), 200);
        });

        $result = $this->makeService()->processMessage($this->user, new Collection, 'Question');

        $this->assertIsString($result);
        $this->assertNotEmpty($result, 'Agent should return a response even after compaction failure');
    }
}
