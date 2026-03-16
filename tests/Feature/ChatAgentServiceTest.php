<?php

namespace Tests\Feature;

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
}
