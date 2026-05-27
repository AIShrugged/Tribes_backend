<?php

namespace App\Services\Chat;

use App\Models\Chat;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class ChatService
{
    public function getChatsForUser(User $user, int $organizationId, int $offset = 0, int $limit = 10): Collection
    {
        return $user->chats()
            ->where('organization_id', $organizationId)
            ->orderByDesc('updated_at')
            ->offset($offset)
            ->limit($limit)
            ->get();
    }

    public function countChatsForUser(User $user, int $organizationId): int
    {
        return $user->chats()
            ->where('organization_id', $organizationId)
            ->count();
    }

    public function create(User $user, ?string $title, int $organizationId, ?int $teamId = null): Chat
    {
        return Chat::create([
            'user_id' => $user->id,
            'organization_id' => $organizationId,
            'team_id' => $teamId,
            'title'   => $title,
        ]);
    }

    public function update(Chat $chat, ?string $title, ?int $organizationId = null, bool $organizationProvided = false, ?int $teamId = null, bool $teamProvided = false): Chat
    {
        $payload = ['title' => $title];

        if ($organizationProvided) {
            $payload['organization_id'] = $organizationId;
        }

        if ($teamProvided) {
            $payload['team_id'] = $teamId;
        }

        $chat->update($payload);

        return $chat->refresh();
    }

    public function delete(Chat $chat): void
    {
        $chat->delete();
    }

    public function findOrFail(int $chatId): Chat
    {
        return Chat::findOrFail($chatId);
    }
}
