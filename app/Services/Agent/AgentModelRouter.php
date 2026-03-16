<?php

namespace App\Services\Agent;

use App\Enums\AgentTaskType;
use App\Models\Setting;

class AgentModelRouter
{
    public function resolve(AgentTaskType $taskType): string
    {
        $settingKey = 'model.'.$taskType->value;
        $default = config('agent.models.'.$taskType->value);

        return (string) Setting::get($settingKey, $default);
    }
}
