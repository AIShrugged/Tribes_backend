<?php

namespace App\Enums;

enum InsightCategory: string
{
    case COMMUNICATION_STYLE  = 'communication_style';
    case WORK_PATTERNS        = 'work_patterns';
    case STRENGTHS            = 'strengths';
    case DEVELOPMENT_AREAS    = 'development_areas';
    case GOALS_MOTIVATIONS    = 'goals_motivations';
    case PSYCHOLOGICAL_PROFILE = 'psychological_profile';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
