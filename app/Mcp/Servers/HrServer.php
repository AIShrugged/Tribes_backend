<?php

namespace App\Mcp\Servers;

use App\Services\Agent\Tools\CreateTaskTool;
use App\Services\Agent\Tools\GetExtractedFactsTool;
use App\Services\Agent\Tools\GetFollowupTool;
use App\Services\Agent\Tools\GetInsightProfileHistoryTool;
use App\Services\Agent\Tools\GetMeetingSummaryTool;
use App\Services\Agent\Tools\GetMeetingTasksTool;
use App\Services\Agent\Tools\GetRelationshipInsightTool;
use App\Services\Agent\Tools\GetTeamMembersTool;
use App\Services\Agent\Tools\GetTranscriptTool;
use App\Services\Agent\Tools\GetUserInfoTool;
use App\Services\Agent\Tools\GetUserInsightsTool;
use App\Services\Agent\Tools\GetUserShortTermMemoryTool;
use App\Services\Agent\Tools\SearchMeetingsTool;
use App\Services\Agent\Tools\UpdateTaskStatusTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Spodial HR')]
#[Version('1.0.0')]
#[Instructions('HR-система Spodial: инсайты сотрудников, профили, история, встречи, фолоапы, задачи, команды, связи между людьми.')]
class HrServer extends Server
{
    protected array $tools = [
        GetUserInfoTool::class,
        GetUserInsightsTool::class,
        GetUserShortTermMemoryTool::class,
        GetExtractedFactsTool::class,
        GetInsightProfileHistoryTool::class,
        GetRelationshipInsightTool::class,
        GetTeamMembersTool::class,
        SearchMeetingsTool::class,
        GetFollowupTool::class,
        GetMeetingSummaryTool::class,
        GetMeetingTasksTool::class,
        CreateTaskTool::class,
        UpdateTaskStatusTool::class,
        GetTranscriptTool::class,
    ];
}
