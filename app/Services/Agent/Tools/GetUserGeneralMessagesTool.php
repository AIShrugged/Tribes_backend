<?php

namespace App\Services\Agent\Tools;

class GetUserGeneralMessagesTool extends AbstractUserChannelMessagesTool
{
    public function getName(): string
    {
        return 'get_user_general_messages';
    }

    public function getDescription(): string
    {
        return 'Get recent messages written by a specific user in general conversations only. General conversations are threads with 3 or more participants.';
    }

    protected function participantCountOperator(): string
    {
        return '>=';
    }

    protected function participantCountValue(): int
    {
        return 3;
    }

    protected function conversationKind(): string
    {
        return 'general';
    }
}
