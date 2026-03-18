<?php

namespace App\Services\Agent;

use App\Models\Chat;
use App\Models\User;
use App\Services\Agent\Tools\CreateArtifactTool;
use App\Services\Agent\Tools\CreateTaskTool;
use App\Services\Agent\Tools\GitHubDownloadArchiveTool;
use App\Services\Agent\Tools\ExecuteSqlQueryTool;
use App\Services\Agent\Tools\GitHubGetBranchTool;
use App\Services\Agent\Tools\GitHubGetFileContentsTool;
use App\Services\Agent\Tools\GitHubGetRepositoryTool;
use App\Services\Agent\Tools\GitHubGetTreeTool;
use App\Services\Agent\Tools\GetCurrentUserTool;
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
use App\Services\Agent\Tools\SearchAgentMemoriesTool;
use App\Services\Agent\Tools\SearchMeetingsTool;
use App\Services\Agent\Tools\ToolRegistry;
use App\Services\Agent\Tools\UpdateMemoryTool;
use App\Services\Agent\Tools\UpdateTaskStatusTool;
use App\Services\AgentMemoryLookupService;
use App\Services\Artifact\ArtifactStateService;
use App\Services\GitHub\GitHubApiClient;
use Illuminate\Support\Facades\Auth;

class AgentToolRegistrar
{
    public function __construct(
        private readonly ArtifactStateService $artifactStateService,
        private readonly AgentMemoryLookupService $agentMemoryLookupService,
        private readonly GitHubApiClient $gitHubApiClient,
    ) {}

    public function registerDefaults(
        ToolRegistry $toolRegistry,
        User $user,
        ?string $channel,
        ?string $sandboxWorkspacePath = null,
        bool $preserveSandboxDependencies = false,
    ): void
    {
        Auth::setUser($user);

        $toolRegistry->register(new UpdateMemoryTool($user, $channel ?? 'web'));
        $toolRegistry->register(new GetCurrentUserTool($user));
        $toolRegistry->register(new GetUserInfoTool);
        $toolRegistry->register(new SearchMeetingsTool);
        $toolRegistry->register(new GetMeetingSummaryTool);
        $toolRegistry->register(new GetMeetingTasksTool);
        $toolRegistry->register(new CreateTaskTool);
        $toolRegistry->register(new UpdateTaskStatusTool);
        $toolRegistry->register(new GetFollowupTool);
        $toolRegistry->register(new GetExtractedFactsTool);
        $toolRegistry->register(new GetUserInsightsTool);
        $toolRegistry->register(new GetInsightProfileHistoryTool);
        $toolRegistry->register(new GetTeamMembersTool);
        $toolRegistry->register(new GetRelationshipInsightTool);
        $toolRegistry->register(new GetUserShortTermMemoryTool);
        $toolRegistry->register(new GetTranscriptTool);
        $toolRegistry->register(new ExecuteSqlQueryTool($user->id));
        $toolRegistry->register(new SearchAgentMemoriesTool($user, $this->agentMemoryLookupService));
        $toolRegistry->register(new GitHubGetBranchTool($this->gitHubApiClient));
        $toolRegistry->register(new GitHubGetRepositoryTool($this->gitHubApiClient));
        $toolRegistry->register(new GitHubGetTreeTool($this->gitHubApiClient));
        $toolRegistry->register(new GitHubGetFileContentsTool($this->gitHubApiClient));

        if ($sandboxWorkspacePath !== null && $sandboxWorkspacePath !== '') {
            $toolRegistry->register(new GitHubDownloadArchiveTool(
                $this->gitHubApiClient,
                $sandboxWorkspacePath,
                $preserveSandboxDependencies,
            ));
        }
    }

    public function registerChatTools(ToolRegistry $toolRegistry, Chat $chat): void
    {
        $toolRegistry->register(new CreateArtifactTool($chat, $this->artifactStateService));
    }
}
