<?php

namespace App\Enums;

enum InsightContextType: string
{
    case CURRENT_PROJECTS  = 'current_projects';
    case RECENT_DECISIONS  = 'recent_decisions';
    case EMOTIONAL_STATE   = 'emotional_state';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
