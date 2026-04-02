<?php

namespace App\Enums;

enum IssueAgentFlowStepKind: string
{
    case VALIDATION = 'validation';
    case PLANNING = 'planning';
    case REVIEW = 'review';
    case EXECUTION = 'execution';
}
