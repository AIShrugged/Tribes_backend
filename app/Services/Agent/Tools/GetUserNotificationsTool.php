<?php

namespace App\Services\Agent\Tools;

use App\Models\User;

class GetUserNotificationsTool implements ToolInterface
{
    public function __construct(private readonly User $user)
    {
    }

    public function getName(): string
    {
        return 'get_user_notifications';
    }

    public function getDescription(): string
    {
        return 'Get the current user\'s dashboard notifications (digests, alerts). '
             . 'Optionally filter to unread only. Useful when the user asks "что у меня в уведомлениях", '
             . '"что мне пришло", or to surface recent digests mid-chat.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'unread_only' => [
                    'type' => 'boolean',
                    'description' => 'If true, only return notifications with read_at IS NULL. Default false.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of notifications to return. Default 10.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $unreadOnly = (bool) ($parameters['unread_only'] ?? false);
        $limit = (int) ($parameters['limit'] ?? 10);
        $limit = max(1, min(50, $limit));

        $query = $this->user->notifications();
        if ($unreadOnly) {
            $query = $this->user->unreadNotifications();
        }

        $items = $query->latest()->limit($limit)->get();

        return [
            'success' => true,
            'count' => $items->count(),
            'notifications' => $items->map(fn ($n) => [
                'id' => $n->id,
                'type' => $n->type,
                'data' => $n->data,
                'read_at' => $n->read_at?->toIso8601String(),
                'created_at' => $n->created_at->toIso8601String(),
            ])->all(),
        ];
    }
}
