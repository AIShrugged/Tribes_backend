<?php

namespace App\Enums;

enum InsightContextType: string
{
    case CURRENT_PROJECTS = 'current_projects';
    case RECENT_DECISIONS = 'recent_decisions';
    case EMOTIONAL_STATE = 'emotional_state';
    case GENERAL_KNOWLEDGE = 'general_knowledge';
    case USER_FOCUS = 'user_focus';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
