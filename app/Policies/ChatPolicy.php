<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

class ChatPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Conversation $conversation): bool
    {
        return $conversation->hasParticipant($user);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Conversation $conversation): bool
    {
        return $conversation->isOwner($user);
    }

    public function delete(User $user, Conversation $conversation): bool
    {
        return $conversation->isOwner($user);
    }

    public function sendMessage(User $user, Conversation $conversation): bool
    {
        return $conversation->hasParticipant($user);
    }
}
