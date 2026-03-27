<?php

namespace Tests\Feature;

use App\Domain\DTO\AI\MessageDTO;
use App\Services\OpenRouterClient;
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
        $fallbackModel = config('ai.providers.openrouter.fallback_models.0');

        Http::fake(function (Request $request) use (&$modelsCalled) {
            $model = $request->data()['model'] ?? null;
            $modelsCalled[] = $model;

            if ($model === 'google/gemini-3-pro-preview') {
                return Http::response([
                    'error' => [
                        'message' => 'No endpoints found for google/gemini-3-pro-preview.',
                        'code' => 404,
                    ],
                ], 404);
            }

            return Http::response([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => '{"ok":true}',
                    ],
                    'finish_reason' => 'stop',
                ]],
            ], 200);
        });

        $result = OpenRouterClient::chat(
            messages: [new MessageDTO('user', 'hello')],
            model: 'google/gemini-3-pro-preview',
            maxTokens: 64,
            forceJsonResponse: true,
        );

        $this->assertSame('{"ok":true}', $result);
        $this->assertSame([
            'google/gemini-3-pro-preview',
            $fallbackModel,
        ], $modelsCalled);
    }

    #[Test]
    public function chat_with_tools_retries_the_next_model_when_the_primary_model_is_unavailable(): void
    {
        $modelsCalled = [];
        $fallbackModel = config('ai.providers.openrouter.fallback_models.0');

        Http::fake(function (Request $request) use (&$modelsCalled) {
            $model = $request->data()['model'] ?? null;
            $modelsCalled[] = $model;

            if ($model === 'google/gemini-3-pro-preview') {
                return Http::response([
                    'error' => [
                        'message' => 'No endpoints found for google/gemini-3-pro-preview.',
                        'code' => 404,
                    ],
                ], 404);
            }

            return Http::response([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'tool result',
                    ],
                    'finish_reason' => 'stop',
                ]],
            ], 200);
        });

        $result = OpenRouterClient::chatWithTools(
            messages: [['role' => 'user', 'content' => 'hello']],
            tools: [['type' => 'function', 'function' => ['name' => 'noop', 'parameters' => ['type' => 'object']]]],
            model: 'google/gemini-3-pro-preview',
            maxTokens: 64,
        );

        $this->assertSame('tool result', $result['choices'][0]['message']['content']);
        $this->assertSame([
            'google/gemini-3-pro-preview',
            $fallbackModel,
        ], $modelsCalled);
    }
}
