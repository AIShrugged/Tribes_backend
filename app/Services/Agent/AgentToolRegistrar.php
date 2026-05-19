<?php

namespace App\Services\Agent;

use App\Models\AgentTask;
use App\Models\AgentTaskRun;
use App\Models\Chat;
use App\Models\Profile;
use App\Models\User;
use App\Services\Agent\Tools\AnswerIssueValidationTool;
use App\Services\Agent\Tools\BuildDailyPlanTool;
use App\Services\Agent\Tools\ClearUserFocusTool;
use App\Services\Agent\Tools\CopyWorkspaceFileTool;
use App\Services\Agent\Tools\CreateArtifactTool;
use App\Services\Agent\Tools\CreateEntityTool;
use App\Services\Agent\Tools\CreateFollowupAgentTaskTool;
use App\Services\Agent\Tools\CreateMethodologyTool;
use App\Services\Agent\Tools\CreateWorkspaceDirectoryTool;
use App\Services\Agent\Tools\CreateWorkspaceTool;
use App\Services\Agent\Tools\DeleteWorkspaceFileTool;
use App\Services\Agent\Tools\DeleteWorkspaceTool;
use App\Services\Agent\Tools\ExecuteSqlQueryTool;
use App\Services\Agent\Tools\FetchDocumentTool;
use App\Services\Agent\Tools\GetCriticalPathTool;
use App\Services\Agent\Tools\GetDailyTaskDigestTool;
use App\Services\Agent\Tools\GetFocusedIssuesTool;
use App\Services\Agent\Tools\GetMeetingAgendaTool;
use App\Services\Agent\Tools\GetPendingIssueValidationsTool;
use App\Services\Agent\Tools\GetUserNotificationsTool;
use App\Services\Agent\Tools\GetTeamMetricsTool;
use App\Services\Agent\Tools\GetTranscriptTool;
use App\Services\Agent\Tools\GetUserFocusTool;
use App\Services\Agent\Tools\GetUserMetricsTool;
use App\Services\Agent\Tools\GetWeeklyTaskDigestTool;
use App\Services\Agent\Tools\GitHubCreateBranchTool;
use App\Services\Agent\Tools\GitHubCreateOrUpdateFileTool;
use App\Services\Agent\Tools\GitHubCreatePullRequestTool;
use App\Services\Agent\Tools\GitHubDownloadArchiveTool;
use App\Services\Agent\Tools\GitHubGetBranchTool;
use App\Services\Agent\Tools\GitHubGetFileContentsTool;
use App\Services\Agent\Tools\GitHubGetPullRequestCommentsTool;
use App\Services\Agent\Tools\GitHubGetRepositoryTool;
use App\Services\Agent\Tools\GitHubGetTreeTool;
use App\Services\Agent\Tools\ListWorkspaceFilesTool;
use App\Services\Agent\Tools\ListWorkspacesTool;
use App\Services\Agent\Tools\MoveWorkspaceFileTool;
use App\Services\Agent\Tools\GetOrganizationContextTool;
use App\Services\Agent\Tools\QueryTribesDataTool;
use App\Services\Agent\Tools\ReadWorkspaceFileTool;
use App\Services\Agent\Tools\SaveMethodologyTool;
use App\Services\Agent\Tools\SearchWorkspaceFilesTool;
use App\Services\Agent\Tools\SendUserMessageTool;
use App\Services\Agent\Tools\SetUserFocusTool;
use App\Services\Agent\Tools\ToolRegistry;
use App\Services\Agent\Tools\UpdateArtifactTool;
use App\Services\Agent\Tools\UpdateEntityTool;
use App\Services\Agent\Tools\WriteWorkspaceFileTool;
use App\Services\AgentMemoryLookupService;
use App\Services\AgentTaskFollowupService;
use App\Services\AgentTaskMutationService;
use App\Services\Artifact\ArtifactStateService;
use App\Services\Channel\ChannelRuntimeService;
use App\Services\Channel\UserChannelTargetResolver;
use App\Services\CriticalPath\CriticalPathService;
use App\Services\GitHub\GitHubApiClient;
use App\Services\IssueAgentFlowService;
use App\Services\JsonSchemaValidationService;
use App\Services\Metrics\PerformanceMetricsService;
use App\Services\TenantScopeValidator;
use App\Services\UserFocusService;
use App\Services\Workspace\WorkspaceAccessService;
use App\Services\Workspace\WorkspaceProvisioningService;
use App\Services\Workspace\WorkspaceService;
use Illuminate\Support\Facades\Auth;

class AgentToolRegistrar
{
    public function __construct(
        private readonly ArtifactStateService $artifactStateService,
        private readonly AgentMemoryLookupService $agentMemoryLookupService,
        private readonly GitHubApiClient $gitHubApiClient,
        private readonly AgentTaskFollowupService $agentTaskFollowupService,
        private readonly AgentTaskMutationService $agentTaskMutationService,
        private readonly JsonSchemaValidationService $schemaValidationService,
        private readonly TenantScopeValidator $tenantScopeValidator,
        private readonly WorkspaceAccessService $workspaceAccessService,
        private readonly WorkspaceProvisioningService $workspaceProvisioningService,
        private readonly WorkspaceService $workspaceService,
        private readonly UserChannelTargetResolver $userChannelTargetResolver,
        private readonly ChannelRuntimeService $channelRuntimeService,
        private readonly IssueAgentFlowService $issueAgentFlowService,
    ) {}

    public function registerDefaults(
        ToolRegistry $toolRegistry,
        User $user,
        ?string $channel,
        ?string $sandboxWorkspacePath = null,
        bool $preserveSandboxDependencies = false,
        ?int $organizationId = null,
        ?int $teamId = null,
        bool $enableSqlTool = true,
    ): void {
        Auth::setUser($user);

        $toolRegistry->register(new QueryTribesDataTool($user, $this->agentMemoryLookupService));
        $toolRegistry->register(new GetOrganizationContextTool($user, $organizationId));
        $toolRegistry->register(new CreateEntityTool($user, $this->tenantScopeValidator, $this->schemaValidationService, $organizationId, $teamId));
        $toolRegistry->register(new UpdateEntityTool($user, $this->agentTaskMutationService, $channel ?? 'web', $organizationId, $teamId));
        $toolRegistry->register(new GetTranscriptTool);
        if ($enableSqlTool) {
            $toolRegistry->register(new ExecuteSqlQueryTool($user->id));
        }
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
        $toolRegistry->register(new GitHubCreateBranchTool($this->gitHubApiClient));
        $toolRegistry->register(new GitHubCreateOrUpdateFileTool($this->gitHubApiClient));
        $toolRegistry->register(new GitHubCreatePullRequestTool($this->gitHubApiClient));
        $toolRegistry->register(new GitHubGetPullRequestCommentsTool($this->gitHubApiClient));
        $toolRegistry->register(new FetchDocumentTool);

        $profile = Profile::where('user_id', $user->id)->first();
        if ($profile) {
            $userFocusService = app(UserFocusService::class);
            $memoryService = app(MemoryService::class);
            $ch = $channel ?? 'web';
            $toolRegistry->register(new SetUserFocusTool($profile, $userFocusService, $memoryService, $ch));
            $toolRegistry->register(new GetUserFocusTool($profile, $userFocusService));
            $toolRegistry->register(new ClearUserFocusTool($profile, $userFocusService, $memoryService, $ch));
            $toolRegistry->register(new GetFocusedIssuesTool($profile, $userFocusService, $user->id));
        }

        $toolRegistry->register(new BuildDailyPlanTool($user, $organizationId, $teamId));
        $toolRegistry->register(new GetCriticalPathTool($user, app(CriticalPathService::class), $organizationId, $teamId));

        $metricsService = app(PerformanceMetricsService::class);
        $toolRegistry->register(new GetUserMetricsTool($user, $metricsService));
        $toolRegistry->register(new GetTeamMetricsTool($user, $metricsService));
        $toolRegistry->register(new GetMeetingAgendaTool);
        $toolRegistry->register(new GetDailyTaskDigestTool($user));
        $toolRegistry->register(new GetWeeklyTaskDigestTool($user));
        $toolRegistry->register(new GetUserNotificationsTool($user));
        $toolRegistry->register(new GetPendingIssueValidationsTool($user));
        $toolRegistry->register(new AnswerIssueValidationTool($user, $this->issueAgentFlowService));

        if ($sandboxWorkspacePath !== null && $sandboxWorkspacePath !== '') {
            $toolRegistry->register(new GitHubDownloadArchiveTool(
                $this->gitHubApiClient,
                $sandboxWorkspacePath,
                $preserveSandboxDependencies,
            ));
        }
    }

    public function registerChatTools(ToolRegistry $toolRegistry, Chat $chat, User $user): void
    {
        $toolRegistry->register(new CreateArtifactTool($chat, $this->artifactStateService));
        $toolRegistry->register(new CreateMethodologyTool($chat));
        $toolRegistry->register(new UpdateArtifactTool($chat, $this->artifactStateService));
        $toolRegistry->register(new SaveMethodologyTool($user, $chat, $this->artifactStateService));
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
