<?php

namespace App\Services\Agent;

use App\Models\Chat;
use App\Models\AgentTask;
use App\Models\AgentTaskRun;
use App\Models\User;
use App\Services\Agent\Tools\CreateArtifactTool;
use App\Services\Agent\Tools\CreateFollowupAgentTaskTool;
use App\Services\Agent\Tools\CreateTaskTool;
use App\Services\Agent\Tools\CreateWorkspaceTool;
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
use App\Services\Agent\Tools\GetUserDirectMessagesTool;
use App\Services\Agent\Tools\GetUserGeneralMessagesTool;
use App\Services\Agent\Tools\GetUserShortTermMemoryTool;
use App\Services\Agent\Tools\SearchAgentMemoriesTool;
use App\Services\Agent\Tools\SearchMeetingsTool;
use App\Services\Agent\Tools\ListWorkspacesTool;
use App\Services\Agent\Tools\ListWorkspaceFilesTool;
use App\Services\Agent\Tools\ReadWorkspaceFileTool;
use App\Services\Agent\Tools\SearchWorkspaceFilesTool;
use App\Services\Agent\Tools\SendUserMessageTool;
use App\Services\Agent\Tools\WriteWorkspaceFileTool;
use App\Services\Agent\Tools\DeleteWorkspaceFileTool;
use App\Services\Agent\Tools\DeleteWorkspaceTool;
use App\Services\Agent\Tools\CopyWorkspaceFileTool;
use App\Services\Agent\Tools\CreateWorkspaceDirectoryTool;
use App\Services\Agent\Tools\MoveWorkspaceFileTool;
use App\Services\Agent\Tools\ToolRegistry;
use App\Services\Agent\Tools\UpdateMemoryTool;
use App\Services\Agent\Tools\UpdateTaskStatusTool;
use App\Services\AgentMemoryLookupService;
use App\Services\Artifact\ArtifactStateService;
use App\Services\GitHub\GitHubApiClient;
use App\Services\AgentTaskFollowupService;
use App\Services\Workspace\WorkspaceAccessService;
use App\Services\Workspace\WorkspaceProvisioningService;
use App\Services\Workspace\WorkspaceService;
use App\Services\Channel\ChannelRuntimeService;
use App\Services\Channel\UserChannelTargetResolver;
use Illuminate\Support\Facades\Auth;

class AgentToolRegistrar
{
    public function __construct(
        private readonly ArtifactStateService $artifactStateService,
        private readonly AgentMemoryLookupService $agentMemoryLookupService,
        private readonly GitHubApiClient $gitHubApiClient,
        private readonly AgentTaskFollowupService $agentTaskFollowupService,
        private readonly WorkspaceAccessService $workspaceAccessService,
        private readonly WorkspaceProvisioningService $workspaceProvisioningService,
        private readonly WorkspaceService $workspaceService,
        private readonly UserChannelTargetResolver $userChannelTargetResolver,
        private readonly ChannelRuntimeService $channelRuntimeService,
    ) {}

    public function registerDefaults(
        ToolRegistry $toolRegistry,
        User $user,
        ?string $channel,
        ?string $sandboxWorkspacePath = null,
        bool $preserveSandboxDependencies = false,
        ?int $organizationId = null,
        ?int $teamId = null,
    ): void
    {
        Auth::setUser($user);

        $toolRegistry->register(new UpdateMemoryTool($user, $channel ?? 'web'));
        $toolRegistry->register(new GetCurrentUserTool($user));
        $toolRegistry->register(new GetUserInfoTool);
        $toolRegistry->register(new SearchMeetingsTool);
        $toolRegistry->register(new GetUserDirectMessagesTool);
        $toolRegistry->register(new GetUserGeneralMessagesTool);
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
        $toolRegistry->register(new SendUserMessageTool($user, $this->userChannelTargetResolver, $this->channelRuntimeService));
        $toolRegistry->register(new ListWorkspacesTool($user, $this->workspaceAccessService, $organizationId, $teamId));
        $toolRegistry->register(new CreateWorkspaceTool($user, $this->workspaceProvisioningService, $this->workspaceAccessService, $organizationId, $teamId));
        $toolRegistry->register(new DeleteWorkspaceTool($user, $this->workspaceService, $organizationId, $teamId));
        $toolRegistry->register(new ListWorkspaceFilesTool($user, $this->workspaceAccessService, $this->workspaceService, $organizationId, $teamId));
        $toolRegistry->register(new ReadWorkspaceFileTool($user, $this->workspaceAccessService, $this->workspaceService, $organizationId, $teamId));
        $toolRegistry->register(new SearchWorkspaceFilesTool($user, $this->workspaceAccessService, $this->workspaceService, $organizationId, $teamId));
        $toolRegistry->register(new CreateWorkspaceDirectoryTool($user, $this->workspaceAccessService, $this->workspaceService, $organizationId, $teamId));
        $toolRegistry->register(new WriteWorkspaceFileTool($user, $this->workspaceAccessService, $this->workspaceService, $organizationId, $teamId));
        $toolRegistry->register(new DeleteWorkspaceFileTool($user, $this->workspaceAccessService, $this->workspaceService, $organizationId, $teamId));
        $toolRegistry->register(new CopyWorkspaceFileTool($user, $this->workspaceAccessService, $this->workspaceService, $organizationId, $teamId));
        $toolRegistry->register(new MoveWorkspaceFileTool($user, $this->workspaceAccessService, $this->workspaceService, $organizationId, $teamId));
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

    public function registerAgentTaskTools(ToolRegistry $toolRegistry, AgentTask $task, ?AgentTaskRun $run = null): void
    {
        if ($run === null) {
            return;
        }

        $toolRegistry->register(new CreateFollowupAgentTaskTool(
            $task,
            $run,
            $this->agentTaskFollowupService,
        ));
    }
}
