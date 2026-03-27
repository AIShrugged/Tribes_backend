<?php

namespace App\Services;

use App\Domain\DTO\AI\MessageDTO;
use App\Exceptions\AppException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenRouterClient
{
    private const URL = 'https://openrouter.ai/api/v1/chat/completions';
    private const RESPONSE_TIMEOUT_SECONDS = 600;

    /**
     * @param MessageDTO[] $messages
     */
    public static function chat(
        array $messages,
        string|array $model,
        int $maxTokens = 1024,
        bool $forceJsonResponse = false
    ): string {
        $lastException = null;

        foreach (self::resolveModelCandidates($model) as $candidate) {
            try {
                return self::chatOnce($messages, $candidate, $maxTokens, $forceJsonResponse);
            } catch (\Throwable $e) {
                $lastException = $e;

                Log::warning('LLM model failed', [
                    'model' => $candidate,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        throw $lastException ?? new AppException('Failed to ask AI', 'AI_REQUEST_FAILED');
    }

    /**
     * @param MessageDTO[] $messages
     */
    private static function chatOnce(
        array $messages,
        string $model,
        int $maxTokens,
        bool $forceJsonResponse
    ): string {
        $payloadMessages = array_map(
            static function (MessageDTO|array $message): array {
                if (is_array($message)) {
                    return $message;
                }

                if (method_exists($message, 'toArray')) {
                    return $message->toArray();
                }

                return [
                    'role'    => $message->role,
                    'content' => $message->content,
                ];
            },
            $messages
        );

        $data = [
            'model'      => $model,
            'messages'   => $payloadMessages,
            'max_tokens' => $maxTokens,
        ];

        if ($forceJsonResponse) {
            $data['response_format'] = ['type' => 'json_object'];
        }

        $options = ['timeout' => self::RESPONSE_TIMEOUT_SECONDS];

        if (config('proxy.enabled')) {
            $options['proxy'] = sprintf(
                'http://%s:%s@%s:%s',
                urlencode(config('proxy.user')),
                urlencode(config('proxy.pass')),
                config('proxy.host'),
                config('proxy.port')
            );
        }

        $response = Http::withOptions($options)
            ->withHeaders(['Authorization' => 'Bearer ' . config('ai.providers.openrouter.api_token')])
            ->post(self::URL, $data);

        Log::info('LLM response', $response->json());

        if (!$response->successful()) {
            throw new AppException('Failed to ask AI', 'AI_REQUEST_FAILED');
        }

        $body = $response->json();

        return $body['choices'][0]['message']['content'] ?? '';
    }

    /**
     * Chat with tools support (for agent loop)
     * Returns full response including tool calls
     *
     * @param array $messages - Array of messages (plain arrays with role/content)
     * @param array|null $tools - Array of tools in OpenAI format
     * @param string $model
     * @param int $maxTokens
     * @param string|null $systemPrompt - System prompt (for Claude models)
     * @return array - Full API response
     */
    public static function chatWithTools(
        array $messages,
        ?array $tools = null,
        string|array $model = 'anthropic/claude-3.5-sonnet',
        int $maxTokens = 4096,
        ?string $systemPrompt = null
    ): array {
        $lastException = null;

        foreach (self::resolveModelCandidates($model) as $candidate) {
            try {
                return self::chatWithToolsOnce($messages, $tools, $candidate, $maxTokens, $systemPrompt);
            } catch (\Throwable $e) {
                $lastException = $e;

                Log::warning('OpenRouter chatWithTools model failed', [
                    'model' => $candidate,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        throw $lastException ?? new AppException('Failed to ask AI', 'AI_REQUEST_FAILED');
    }

    private static function chatWithToolsOnce(
        array $messages,
        ?array $tools,
        string $model,
        int $maxTokens,
        ?string $systemPrompt
    ): array {
        $data = [
            'model'      => $model,
            'messages'   => $messages,
            'max_tokens' => $maxTokens,
        ];

        if ($systemPrompt) {
            $data['system'] = $systemPrompt;
        }

        if ($tools) {
            $data['tools'] = $tools;
            $data['tool_choice'] = 'auto';
        }

        Log::info('OpenRouter chatWithTools request', [
            'model' => $model,
            'messages_count' => count($messages),
            'tools_count' => $tools ? count($tools) : 0,
            'has_system_prompt' => !empty($systemPrompt),
        ]);

        //TODO: bring back proxy
        $response = Http::timeout(self::RESPONSE_TIMEOUT_SECONDS)
            ->connectTimeout(self::RESPONSE_TIMEOUT_SECONDS)
            ->withHeaders(['Authorization' => 'Bearer ' . config('ai.providers.openrouter.api_token')])
            ->post(self::URL, $data);

        if (!$response->successful()) {
            Log::error('OpenRouter API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new AppException('Failed to ask AI: ' . $response->body(), 'AI_REQUEST_FAILED');
        }

        $body = $response->json();

        Log::info('OpenRouter chatWithTools response', [
            'finish_reason' => $body['choices'][0]['finish_reason'] ?? null,
            'has_tool_calls' => isset($body['choices'][0]['message']['tool_calls']),
        ]);

        return $body;
    }

    /**
     * @param string|array $model
     * @return array<int, string>
     */
    private static function resolveModelCandidates(string|array $model): array
    {
        $fallbackModels = config('ai.providers.openrouter.fallback_models', []);

        if (is_array($model)) {
            $candidates = $model;
        } else {
            $candidates = array_merge([$model], is_array($fallbackModels) ? $fallbackModels : []);
        }

        return array_values(array_unique(array_filter($candidates, static fn ($value) => is_string($value) && $value !== '')));
    }
}
