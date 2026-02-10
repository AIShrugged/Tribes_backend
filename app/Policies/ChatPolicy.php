<?php

namespace App\Policies;

use App\Models\Chat;
use App\Models\User;

class ChatPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Chat $chat): bool
    {
        return $chat->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Chat $chat): bool
    {
        return $chat->user_id === $user->id;
    }

    public function delete(User $user, Chat $chat): bool
    {
        return $chat->user_id === $user->id;
    }

    public function sendMessage(User $user, Chat $chat): bool
    {
        return $chat->user_id === $user->id;
    }
}
