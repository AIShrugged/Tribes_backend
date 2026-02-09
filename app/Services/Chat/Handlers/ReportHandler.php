<?php

namespace App\Services\Chat\Handlers;

use App\Domain\DTO\AI\MessageDTO;
use App\Domain\DTO\Chat\ChartConfigDTO;
use App\Domain\DTO\Chat\WandaResponseDTO;
use App\Services\Chat\ChatMessageService;
use App\Services\Chat\FollowupAccessService;
use App\Services\Chat\FollowupHtmlRenderer;
use App\Services\Chat\SqlQueryExecutor;
use App\Services\Chat\Visualization\SvgChartRenderer;
use App\Services\Chat\WandaPromptBuilder;
use App\Services\Chat\WandaResponseParser;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ReportHandler
{
    private const OPENROUTER_URL = 'https://openrouter.ai/api/v1/chat/completions';
    private const MAX_DATA_FOR_INTERPRETATION = 8192;

    public function __construct(
        private readonly ChatMessageService $messageService,
        private readonly WandaPromptBuilder $promptBuilder,
        private readonly WandaResponseParser $responseParser,
        private readonly FollowupAccessService $accessService,
        private readonly FollowupHtmlRenderer $htmlRenderer,
        private readonly SqlQueryExecutor $sqlExecutor,
        private readonly SvgChartRenderer $chartRenderer,
    ) {
    }

    public function handle(User $user, Chat $chat, string $content): ChatMessage
    {
        $history = $this->messageService->getRecentHistory($chat);
        $messages = $this->promptBuilder->buildMessages($user, $history, $content);

        // Step 1: LLM generates SQL + visualization config
        $llmResponse = $this->callLLM($messages);
        $parsedResponse = $this->responseParser->parse($llmResponse);

        // No SQL — pure text response
        if (!$parsedResponse->hasSqlQuery()) {
            return $this->messageService->createAssistantMessage(
                $chat,
                $parsedResponse->message,
            );
        }

        // Execute SQL
        $accessibleUserIds = $this->accessService->getAccessibleUserIds($user);
        $queryResult = $this->sqlExecutor->execute($parsedResponse->sql, $accessibleUserIds);

        // SQL execution failed
        if (!$queryResult->success) {
            Log::warning('Wanda SQL failed', ['error' => $queryResult->error]);

            return $this->messageService->createAssistantMessage(
                $chat,
                $parsedResponse->message . "\n\n⚠️ Не удалось выполнить запрос к базе данных.",
            );
        }

        // Empty result
        if ($queryResult->rowCount === 0) {
            return $this->messageService->createAssistantMessage(
                $chat,
                $parsedResponse->message,
                ['type' => 'empty', 'html' => $this->htmlRenderer->renderNotFound()],
            );
        }

        // Step 2: Send data to LLM for interpretation
        $interpretation = $this->interpretResults(
            $messages,
            $llmResponse,
            $queryResult->data,
        );

        // Use visualization from interpretation if provided, otherwise from step 1
        $visualization = $interpretation->visualization ?? $parsedResponse->visualization;

        // Render visualization
        $followupData = $this->renderVisualization($visualization, $queryResult->data);

        return $this->messageService->createAssistantMessage(
            $chat,
            $interpretation->message,
            $followupData,
        );
    }

    private function interpretResults(array $originalMessages, string $step1Response, array $data): WandaResponseDTO
    {
        $dataJson = json_encode($data, JSON_UNESCAPED_UNICODE);

        if (strlen($dataJson) > self::MAX_DATA_FOR_INTERPRETATION) {
            $dataJson = substr($dataJson, 0, self::MAX_DATA_FOR_INTERPRETATION) . '... (данные обрезаны)';
        }

        $messages = $originalMessages;
        $messages[] = new MessageDTO('assistant', $step1Response);
        $messages[] = new MessageDTO('user', <<<PROMPT
Результат SQL-запроса ({$this->countRows($data)} строк):
```json
{$dataJson}
```

Проанализируй данные и дай развёрнутый ответ на русском языке.
Ответь JSON:
```json
{
  "message": "Анализ результатов...",
  "visualization": {"type": "line|bar|pie|table|metric|none", "title": "Заголовок"}
}
```
PROMPT);

        $llmResponse = $this->callLLM($messages);

        return $this->responseParser->parseInterpretation($llmResponse);
    }

    private function renderVisualization(?ChartConfigDTO $visualization, array $data): array
    {
        if (!$visualization || !$visualization->hasVisualization()) {
            $visualization = new ChartConfigDTO(
                type: ChartConfigDTO::TYPE_TABLE,
                title: 'Результаты запроса',
            );
        }

        $normalizedData = $this->normalizeChartData($visualization->type, $data);
        $html = $this->chartRenderer->render($visualization, $normalizedData);

        return [
            'type' => 'visualization',
            'visualization_type' => $visualization->type,
            'data_count' => count($data),
            'html' => $html,
        ];
    }

    private function normalizeChartData(string $chartType, array $data): array
    {
        if (empty($data)) {
            return $data;
        }

        if ($chartType === ChartConfigDTO::TYPE_TABLE) {
            return $data;
        }

        $firstRow = $data[0];

        if (array_key_exists('label', $firstRow) && array_key_exists('value', $firstRow)) {
            return $data;
        }

        $keys = array_keys($firstRow);
        if (count($keys) < 2) {
            return $data;
        }

        $labelKey = $keys[0];
        $valueKey = $keys[1];

        return array_map(function ($row) use ($labelKey, $valueKey) {
            return [
                'label' => $row[$labelKey] ?? '',
                'value' => $row[$valueKey] ?? 0,
            ];
        }, $data);
    }

    private function countRows(array $data): int
    {
        return count($data);
    }

    private function callLLM(array $messages): string
    {
        $payloadMessages = array_map(function ($message) {
            return [
                'role'    => $message->role,
                'content' => $message->content,
            ];
        }, $messages);

        $response = Http::timeout(120)
            ->withHeaders([
                'Authorization' => 'Bearer ' . config('ai.providers.openrouter.api_token'),
                'Content-Type'  => 'application/json',
            ])
            ->post(self::OPENROUTER_URL, [
                'model'      => config('ai.providers.openrouter.models.wanda'),
                'messages'   => $payloadMessages,
                'max_tokens' => 4096,
            ]);

        Log::info('Wanda LLM response', ['status' => $response->status()]);

        if (!$response->successful()) {
            Log::error('Wanda LLM error', ['body' => $response->body()]);
            throw new \Exception('LLM request failed: ' . $response->status());
        }

        return $response->json('choices.0.message.content') ?? '';
    }
}
