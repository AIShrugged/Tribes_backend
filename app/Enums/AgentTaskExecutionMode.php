<?php

namespace App\Enums;

enum AgentTaskExecutionMode: string
{
    case INLINE = 'inline';
    case ISOLATED = 'isolated';
}
