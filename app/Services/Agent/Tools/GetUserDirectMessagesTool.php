<?php

namespace App\Services\Agent\Tools;

class GetUserDirectMessagesTool extends AbstractUserChannelMessagesTool
{
    public function getName(): string
    {
        return 'get_user_direct_messages';
    }

    public function getDescription(): string
    {
        return 'Get recent messages written by a specific user in direct conversations only. Direct conversations are threads with exactly 2 participants.';
    }

    protected function participantCountOperator(): string
    {
        return '=';
    }

    protected function participantCountValue(): int
    {
        return 2;
    }

    protected function conversationKind(): string
    {
        return 'direct';
    }
}
