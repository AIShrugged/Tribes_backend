<?php

namespace App\Enums;

enum IssueAgentFlowStepKind: string
{
    case PLANNING = 'planning';
    case EXECUTION = 'execution';
}
