<?php

namespace Tests\Feature;

use App\Domain\DTO\AI\MessageDTO;
use App\Services\AnthropicClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OpenRouterClientFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected bool $mockLlm = false;

    #[Test]
    public function chat_retries_the_next_model_when_the_primary_model_is_unavailable(): void
    {
        $modelsCalled = [];
        $primaryModel  = 'claude-bad-model';
        $fallbackModel = config('ai.providers.anthropic.fallback_models.0');

        Http::fake(function (Request $request) use (&$modelsCalled, $primaryModel) {
            $model = $request->data()['model'] ?? null;
            $modelsCalled[] = $model;

            if ($model === $primaryModel) {
                return Http::response([
                    'type'    => 'error',
                    'error'   => ['type' => 'not_found_error', 'message' => 'model not found'],
                ], 404);
            }

            // Successful Anthropic response
            return Http::response([
                'id'           => 'msg_01test',
                'type'         => 'message',
                'role'         => 'assistant',
                'content'      => [['type' => 'text', 'text' => '{"ok":true}']],
                'stop_reason'  => 'end_turn',
                'model'        => $model,
                'usage'        => ['input_tokens' => 10, 'output_tokens' => 5],
            ], 200);
        });

        $result = AnthropicClient::chat(
            messages: [new MessageDTO('user', 'hello')],
            model: $primaryModel,
            maxTokens: 64,
            forceJsonResponse: true,
        );

        $this->assertSame('{"ok":true}', $result);
        $this->assertSame([$primaryModel, $fallbackModel], $modelsCalled);
    }

    #[Test]
    public function chat_with_tools_retries_the_next_model_when_the_primary_model_is_unavailable(): void
    {
        $modelsCalled = [];
        $primaryModel  = 'claude-bad-model';
        $fallbackModel = config('ai.providers.anthropic.fallback_models.0');

        Http::fake(function (Request $request) use (&$modelsCalled, $primaryModel) {
            $model = $request->data()['model'] ?? null;
            $modelsCalled[] = $model;

            if ($model === $primaryModel) {
                return Http::response([
                    'type'  => 'error',
                    'error' => ['type' => 'not_found_error', 'message' => 'model not found'],
                ], 404);
            }

            // Successful Anthropic response
            return Http::response([
                'id'          => 'msg_01test',
                'type'        => 'message',
                'role'        => 'assistant',
                'content'     => [['type' => 'text', 'text' => 'tool result']],
                'stop_reason' => 'end_turn',
                'model'       => $model,
                'usage'       => ['input_tokens' => 10, 'output_tokens' => 5],
            ], 200);
        });

        $result = AnthropicClient::chatWithTools(
            messages: [['role' => 'user', 'content' => 'hello']],
            tools: [['type' => 'function', 'function' => ['name' => 'noop', 'parameters' => ['type' => 'object']]]],
            model: $primaryModel,
            maxTokens: 64,
        );

        $this->assertSame('tool result', $result['choices'][0]['message']['content']);
        $this->assertSame([$primaryModel, $fallbackModel], $modelsCalled);
    }
}
