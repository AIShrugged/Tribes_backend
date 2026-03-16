<?php

namespace App\Services\Agent\Tools;

use App\Enums\ConversationChannelType;
use App\Models\ChannelMessage;
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
                    'description' => 'Get messages starting from this datetime. Accepts "YYYY-MM-DD", "YYYY-MM-DD HH:MM:SS", or ISO 8601.',
                ],
                'until' => [
                    'type' => 'string',
                    'description' => 'Get messages until this datetime (end of day if only date given). Accepts "YYYY-MM-DD", "YYYY-MM-DD HH:MM:SS", or ISO 8601.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $parameters = $parameters ?? [];
        $limit = min($parameters['limit'] ?? 20, 100);

        $query = ChannelMessage::query()
            ->whereHas('conversation', function ($conversationQuery) {
                $conversationQuery
                    ->where('channel_type', ConversationChannelType::TELEGRAM->value)
                    ->where('telegram_chat_id', $this->telegramChatId);
            });

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
                $until = Carbon::parse($parameters['until']);
                if (!str_contains($parameters['until'], ':')) {
                    $until = $until->endOfDay();
                }
                $query->where('created_at', '<=', $until);
            } catch (\Exception $e) {
                return [
                    'success' => false,
                    'error' => 'Invalid until date format. Use YYYY-MM-DD or YYYY-MM-DD HH:MM:SS',
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
                'author' => $m->authorIdentity?->display_name ?? $m->authorIdentity?->external_id,
                'username' => $m->authorIdentity?->username,
                'external_author_id' => $m->authorIdentity?->external_id,
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
