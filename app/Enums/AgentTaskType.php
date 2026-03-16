<?php

namespace App\Enums;

enum AgentTaskType: string
{
    case INTERACTIVE = 'interactive';
    case SUMMARIZATION = 'summarization';
    case EXTRACTION = 'extraction';
    case BACKGROUND = 'background';
}
