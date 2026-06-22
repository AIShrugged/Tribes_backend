<?php

namespace App\Mcp\Servers;

use App\Services\Agent\Tools\GetCurrentUserTool;
use App\Services\Agent\Tools\GetFollowupTool;
use App\Services\Agent\Tools\GetInsightProfileHistoryTool;
use App\Services\Agent\Tools\GetMeetingAgendaTool;
use App\Services\Agent\Tools\GetMeetingSummaryTool;
use App\Services\Agent\Tools\GetMeetingTasksTool;
use App\Services\Agent\Tools\GetOpenIssuesTool;
use App\Services\Agent\Tools\GetOrganizationContextTool;
use App\Services\Agent\Tools\GetRelationshipInsightTool;
use App\Services\Agent\Tools\GetTeamMembersTool;
use App\Services\Agent\Tools\GetTranscriptTool;
use App\Services\Agent\Tools\GetUserInfoTool;
use App\Services\Agent\Tools\GetUserInsightsTool;
use App\Services\Agent\Tools\QueryTribesDataTool;
use App\Services\Agent\Tools\SaveTeamDecisionTool;
use App\Services\Agent\Tools\SearchMeetingsTool;
use App\Services\Agent\Tools\SearchTeamDecisionsTool;
use App\Services\Agent\Tools\SuggestActionTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * MCP server consumed by external autonomous clients (e.g. the "second brain"
 * sidecar). Every tool below resolves its tenant scope from the authenticated
 * Sanctum user (see App\Services\Agent\Tools\Concerns\InteractsWithMcpTenant),
 * so a per-organization service token only ever sees/touches that org's data.
 *
 * Only task-reconciliation tools are exposed here. People-profiling / insight
 * tools (get_user_info, get_user_insights, get_extracted_facts, relationship/
 * profile-history, channel messages) are intentionally NOT exposed over MCP:
 * they carry PII and are not needed by the brain. They remain available on the
 * internal agent path via AgentToolRegistrar.
 */
#[Name('TribesMCP')]
#[Version('1.0.0')]
#[Instructions('HR-система TribesMCP: встречи и транскрипты, фолоапы, задачи/issues, командные решения, участники команд. Все данные ограничены организацией текущего токена.')]
class TribesMcpServer extends Server
{
    /**
     * Return all tools in a single tools/list page (we expose 18; the framework
     * default is 15, which pushes suggest_action onto page 2). A client that does
     * not follow nextCursor would then miss the brain's only write tool. 50 is the
     * framework's maxPaginationLength, so every tool is always visible at once.
     */
    public int $defaultPaginationLength = 50;

    protected array $tools = [
        // Identity / context
        GetCurrentUserTool::class,
        GetOrganizationContextTool::class,
        GetTeamMembersTool::class,

        // Universal read (mirrors the internal agent's primary tool, scoped by org)
        QueryTribesDataTool::class,

        // Tasks / issues
        GetOpenIssuesTool::class,
        GetMeetingTasksTool::class,

        // Meetings
        SearchMeetingsTool::class,
        GetMeetingSummaryTool::class,
        GetMeetingAgendaTool::class,
        GetTranscriptTool::class,
        GetFollowupTool::class,

        // Decisions
        SearchTeamDecisionsTool::class,
        SaveTeamDecisionTool::class,

        // People / insights (PII — gated to the caller's organization over MCP)
        GetUserInfoTool::class,
        GetUserInsightsTool::class,
        GetRelationshipInsightTool::class,
        GetInsightProfileHistoryTool::class,

        // The brain's ONLY write: propose an action for human approval.
        // It does NOT create issues or change statuses directly — see
        // brain_suggestions + BrainSuggestionController (approve/reject).
        SuggestActionTool::class,
    ];
}
