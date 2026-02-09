<?php

namespace App\Services\Chat;

use App\Models\Chat;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class ChatService
{
    public function getChatsForUser(User $user, int $offset = 0, int $limit = 10): Collection
    {
        return $user->chats()
            ->orderByDesc('updated_at')
            ->offset($offset)
            ->limit($limit)
            ->get();
    }

    public function countChatsForUser(User $user): int
    {
        return $user->chats()->count();
    }

    public function create(User $user, ?string $title = null): Chat
    {
        return Chat::create([
            'user_id' => $user->id,
            'title'   => $title,
        ]);
    }

    public function update(Chat $chat, ?string $title): Chat
    {
        $chat->update(['title' => $title]);

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
