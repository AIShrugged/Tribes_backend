<?php

namespace App\Enums;

enum InsightRelationshipType: string
{
    case COLLABORATIVE = 'collaborative';
    case CONFLICTING   = 'conflicting';
    case HIERARCHICAL  = 'hierarchical';
    case NEUTRAL       = 'neutral';
}
