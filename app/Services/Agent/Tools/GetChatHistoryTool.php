<?php

namespace App\Services\Agent\Tools;

use App\Models\TelegramChatMessage;
use Illuminate\Support\Carbon;

class GetChatHistoryTool implements ToolInterface
{
    private int $telegramChatId;

    public function __construct(int $telegramChatId)
    {
        $this->telegramChatId = $telegramChatId;
    }

    public function getName(): string
    {
        return 'get_chat_history';
    }

    public function getDescription(): string
    {
        return 'Retrieve recent messages from the current chat conversation. Use this to recall what was discussed earlier in this chat. Returns messages in chronological order.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Number of recent messages to retrieve (default 20, max 100)',
                ],
                'since' => [
                    'type' => 'string',
                    'description' => 'Get messages starting from this date (YYYY-MM-DD format)',
                ],
                'until' => [
                    'type' => 'string',
                    'description' => 'Get messages until this date (YYYY-MM-DD format)',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $parameters = $parameters ?? [];
        $limit = min($parameters['limit'] ?? 20, 100);

        $query = TelegramChatMessage::where('telegram_chat_id', $this->telegramChatId);

        if (!empty($parameters['since'])) {
            try {
                $since = Carbon::parse($parameters['since'])->startOfDay();
                $query->where('created_at', '>=', $since);
            } catch (\Exception $e) {
                return [
                    'success' => false,
                    'error' => 'Invalid since date format. Use YYYY-MM-DD',
                ];
            }
        }

        if (!empty($parameters['until'])) {
            try {
                $until = Carbon::parse($parameters['until'])->endOfDay();
                $query->where('created_at', '<=', $until);
            } catch (\Exception $e) {
                return [
                    'success' => false,
                    'error' => 'Invalid until date format. Use YYYY-MM-DD',
                ];
            }
        }

        $messages = $query
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->map(fn($m) => [
                'role' => $m->role,
                'content' => $m->content,
                'username' => $m->telegramUser?->telegram_username,
                'created_at' => $m->created_at->toDateTimeString(),
            ])
            ->toArray();

        return [
            'success' => true,
            'count' => count($messages),
            'messages' => $messages,
        ];
    }
}
