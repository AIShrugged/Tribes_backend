<?php

namespace App\Services;

use App\Domain\DTO\AI\MessageDTO;
use App\Exceptions\AppException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AnthropicClient
{
    private const URL = 'https://api.anthropic.com/v1/messages';
    private const RESPONSE_TIMEOUT_SECONDS = 600;
    private const ANTHROPIC_VERSION = '2023-06-01';

    /**
     * Simple text chat — returns the assistant's text content.
     *
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
        $systemPrompt = null;
        $filteredMessages = [];

        foreach ($messages as $message) {
            if (is_array($message)) {
                $msg = $message;
            } elseif (method_exists($message, 'toArray')) {
                $msg = $message->toArray();
            } else {
                $msg = ['role' => $message->role, 'content' => $message->content];
            }

            if ($msg['role'] === 'system') {
                $systemPrompt = $msg['content'];
            } else {
                $filteredMessages[] = $msg;
            }
        }

        $data = [
            'model'      => $model,
            'messages'   => $filteredMessages,
            'max_tokens' => $maxTokens,
        ];

        if ($systemPrompt !== null) {
            $data['system'] = $systemPrompt;
        }

        // Anthropic does not support `response_format`; callers should instruct JSON in their prompts.

        $body = self::makeRequest($data);

        return $body['content'][0]['text'] ?? '';
    }

    /**
     * Chat with tools support (for the agent loop).
     * Returns an OpenAI-compatible response structure for backwards compatibility.
     *
     * @param array                $messages      Messages array (plain arrays with role/content)
     * @param array|null           $tools         Tools in OpenAI function-calling format
     * @param string|array         $model         Model name or fallback list
     * @param int                  $maxTokens
     * @param string|null          $systemPrompt  System prompt text
     * @return array               OpenAI-shaped response with choices[0].message
     */
    public static function chatWithTools(
        array $messages,
        ?array $tools = null,
        string|array $model = 'claude-sonnet-4-6',
        int $maxTokens = 4096,
        ?string $systemPrompt = null
    ): array {
        $lastException = null;

        foreach (self::resolveModelCandidates($model) as $candidate) {
            try {
                return self::chatWithToolsOnce($messages, $tools, $candidate, $maxTokens, $systemPrompt);
            } catch (\Throwable $e) {
                $lastException = $e;

                Log::warning('AnthropicClient chatWithTools model failed', [
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

        if ($systemPrompt !== null) {
            $data['system'] = $systemPrompt;
        }

        if ($tools) {
            $data['tools'] = self::convertToolsToAnthropic($tools);
        }

        Log::info('AnthropicClient chatWithTools request', [
            'model'            => $model,
            'messages_count'   => count($messages),
            'tools_count'      => $tools ? count($tools) : 0,
            'has_system_prompt' => $systemPrompt !== null,
        ]);

        $body = self::makeRequest($data);

        Log::info('AnthropicClient chatWithTools response', [
            'stop_reason'   => $body['stop_reason'] ?? null,
            'content_types' => array_column($body['content'] ?? [], 'type'),
        ]);

        return self::convertResponseToOpenAI($body, $model);
    }

    /**
     * Make an HTTP request to the Anthropic Messages API.
     */
    private static function makeRequest(array $data): array
    {
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
            ->withHeaders([
                'x-api-key'         => config('ai.providers.anthropic.api_key'),
                'anthropic-version' => self::ANTHROPIC_VERSION,
            ])
            ->post(self::URL, $data);

        if (!$response->successful()) {
            Log::error('Anthropic API error', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            throw new AppException('Failed to ask AI: ' . $response->body(), 'AI_REQUEST_FAILED');
        }

        $body = $response->json();

        Log::info('Anthropic API response', ['stop_reason' => $body['stop_reason'] ?? null]);

        return $body;
    }

    /**
     * Convert OpenAI-format tool definitions to Anthropic format.
     *
     * OpenAI:   [{type: "function", function: {name, description, parameters}}]
     * Anthropic: [{name, description, input_schema}]
     */
    private static function convertToolsToAnthropic(array $tools): array
    {
        return array_map(static function (array $tool): array {
            if (isset($tool['function'])) {
                return [
                    'name'         => $tool['function']['name'],
                    'description'  => $tool['function']['description'] ?? '',
                    'input_schema' => $tool['function']['parameters'] ?? ['type' => 'object', 'properties' => []],
                ];
            }

            // Already in Anthropic format — pass through.
            return $tool;
        }, $tools);
    }

    /**
     * Convert an Anthropic Messages response to an OpenAI-compatible shape
     * so existing callers do not need to change.
     *
     * Anthropic stop_reason → OpenAI finish_reason mapping:
     *   end_turn  → stop
     *   tool_use  → tool_calls
     *   max_tokens → length
     */
    private static function convertResponseToOpenAI(array $anthropicResponse, string $model): array
    {
        $content    = $anthropicResponse['content'] ?? [];
        $stopReason = $anthropicResponse['stop_reason'] ?? 'end_turn';

        $textContent = null;
        $toolCalls   = [];

        foreach ($content as $block) {
            if ($block['type'] === 'text') {
                $textContent = $block['text'];
            } elseif ($block['type'] === 'tool_use') {
                $toolCalls[] = [
                    'id'       => $block['id'],
                    'type'     => 'function',
                    'function' => [
                        'name'      => $block['name'],
                        'arguments' => json_encode($block['input']),
                    ],
                ];
            }
        }

        $message = ['role' => 'assistant', 'content' => $textContent];

        if (!empty($toolCalls)) {
            $message['tool_calls'] = $toolCalls;
        }

        $finishReason = match ($stopReason) {
            'end_turn'   => 'stop',
            'tool_use'   => 'tool_calls',
            'max_tokens' => 'length',
            default      => $stopReason,
        };

        return [
            'id'      => $anthropicResponse['id'] ?? null,
            'model'   => $model,
            'choices' => [
                [
                    'index'         => 0,
                    'message'       => $message,
                    'finish_reason' => $finishReason,
                ],
            ],
            'usage'   => $anthropicResponse['usage'] ?? null,
        ];
    }

    /**
     * @param  string|array  $model
     * @return array<int, string>
     */
    private static function resolveModelCandidates(string|array $model): array
    {
        $fallbackModels = config('ai.providers.anthropic.fallback_models', []);

        if (is_array($model)) {
            $candidates = $model;
        } else {
            $candidates = array_merge([$model], is_array($fallbackModels) ? $fallbackModels : []);
        }

        return array_values(array_unique(array_filter(
            $candidates,
            static fn ($value) => is_string($value) && $value !== ''
        )));
    }
}
