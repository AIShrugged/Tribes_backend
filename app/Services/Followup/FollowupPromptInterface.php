<?php

namespace App\Services\Followup;

interface FollowupPromptInterface
{
    public function getSystemPrompt(): string;

    public function getUserPrompt(): string;
}
