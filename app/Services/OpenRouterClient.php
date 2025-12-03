<?php

namespace App\Services;

use App\Domain\DTO\AI\MessageDTO;
use App\Exceptions\AppException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenRouterClient
{
    private const URL = 'https://openrouter.ai/api/v1/chat/completions';
    private const RESPONSE_TIMEOUT_SECONDS = 120;

    /**
     * @param MessageDTO[] $messages
     */
    public static function chat(
        array $messages,
        string $model,
        int $maxTokens = 1024,
        bool $forceJsonResponse = false
    ): string {
        $payloadMessages = array_map(
            static function (MessageDTO $message): array {
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

        $response = Http::withProxy()
            ->timeout(self::RESPONSE_TIMEOUT_SECONDS)
            ->withHeaders(['Authorization' => 'Bearer ' . config('ai.providers.openrouter.api_token')])
            ->post(self::URL, $data);

        Log::info('LLM response', $response->json());

        if (!$response->successful()) {
            throw new AppException('Failed to ask AI', 'AI_REQUEST_FAILED');
        }

        $body = $response->json();

        return $body['choices'][0]['message']['content'] ?? '';
    }
}
