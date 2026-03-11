<?php

namespace App\Services\Agent\Tools;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Carbon;

class GetChatHistoryTool implements ToolInterface
{
    public function __construct(private readonly Conversation $conversation)
    {
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
        $limit      = min($parameters['limit'] ?? 20, 100);

        $query = Message::where('conversation_id', $this->conversation->id);

        if (!empty($parameters['since'])) {
            try {
                $since = Carbon::parse($parameters['since'])->startOfDay();
                $query->where('created_at', '>=', $since);
            } catch (\Exception $e) {
                return ['success' => false, 'error' => 'Invalid since date format. Use YYYY-MM-DD'];
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
                return ['success' => false, 'error' => 'Invalid until date format. Use YYYY-MM-DD or YYYY-MM-DD HH:MM:SS'];
            }
        }

        $messages = $query
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->map(fn(Message $m) => [
                'role'       => $m->role,
                'content'    => $m->content,
                'username'   => $m->sender_type && $m->sender ? ($m->sender->telegram_username ?? null) : null,
                'created_at' => $m->created_at->toDateTimeString(),
            ])
            ->toArray();

        return [
            'success'  => true,
            'count'    => count($messages),
            'messages' => $messages,
        ];
    }
}
