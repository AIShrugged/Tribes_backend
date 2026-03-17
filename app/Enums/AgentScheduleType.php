<?php

namespace App\Enums;

enum AgentScheduleType: string
{
    case ONE_OFF = 'one_off';
    case INTERVAL = 'interval';
}
