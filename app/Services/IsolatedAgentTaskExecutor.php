<?php

namespace App\Services;

use App\Models\AgentTask;
use App\Models\AgentTaskRun;
use App\Services\Workspace\WorkspaceAccessService;
use App\Services\Workspace\WorkspaceService;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use function parse_url;

class IsolatedAgentTaskExecutor
{
    public function __construct(
        private readonly AgentTaskRunTokenService $runTokenService,
        private readonly AgentTaskToolExecutor $toolExecutor,
        private readonly AgentTaskContextBuilder $contextBuilder,
        private readonly AgentMemoryIngestionService $memoryIngestionService,
        private readonly WorkspaceAccessService $workspaceAccessService,
        private readonly WorkspaceService $workspaceService,
    ) {}

    public function execute(AgentTask $task, AgentTaskRun $run): string
    {
        $user = $task->user()->first();
        if (! $user) {
            throw new \RuntimeException('Agent task user not found');
        }

        $plainToken = $this->runTokenService->issue($run);
        $context = $this->contextBuilder->build($task);
        $gatewayBaseUrl = rtrim((string) config('agent.agent_tasks.sandbox_internal_base_url', 'http://app'), '/');
        $gatewayHost = parse_url($gatewayBaseUrl, PHP_URL_HOST);
        $allowedOutboundHosts = $context['allowed_outbound_hosts'];
        if ($task->restrictsOutboundHosts() && is_string($gatewayHost) && $gatewayHost !== '') {
            $allowedOutboundHosts[] = $gatewayHost;
        }
        $allowedOutboundHosts = array_values(array_unique($allowedOutboundHosts));
        $workspace = storage_path('app/private/sandbox-runs/'.$run->id);
        $mountWorkspace = $this->resolveDockerWorkspaceMount($run->id, $workspace);
        $persistentWorkspace = $this->resolvePersistentWorkspace($task);
        $inputDir = $workspace.'/input';
        $outputDir = $workspace.'/output';
        $artifactsDir = $workspace.'/artifacts';
        $syncedWorkspacesDir = $workspace.'/synced-workspaces';

        File::ensureDirectoryExists($inputDir);
        File::ensureDirectoryExists($outputDir);
        File::ensureDirectoryExists($artifactsDir);
        File::ensureDirectoryExists($syncedWorkspacesDir);
        if ($persistentWorkspace !== null) {
            File::ensureDirectoryExists($persistentWorkspace['host_path']);
            @chmod($persistentWorkspace['host_path'], 0777);
        }
        @chmod($workspace, 0777);
        @chmod($inputDir, 0777);
        @chmod($outputDir, 0777);
        @chmod($artifactsDir, 0777);
        @chmod($syncedWorkspacesDir, 0777);

        $workspaceManifest = $this->workspaceAccessService->manifestForUser(
            $user,
            null,
            $task->organization_id,
            $task->team_id,
        )->all();
        $materializedWorkspaces = $this->workspaceService->materializeWorkspaces($workspaceManifest, $syncedWorkspacesDir);

        $payload = [
            'task' => [
                'id' => $task->id,
                'run_id' => $run->id,
                'name' => $task->name,
                'organization_id' => $task->organization_id,
                'team_id' => $task->team_id,
                'prompt' => $context['user_prompt'],
                'sandbox_profile' => $context['sandbox_profile'],
                'allowed_tools' => $context['allowed_tools'],
                'allowed_outbound_hosts' => $allowedOutboundHosts,
                'max_iterations' => (int) ($task->metadata['max_iterations'] ?? 8),
                'input_payload' => $context['input_payload'],
                'lineage' => $context['task_lineage'],
            ],
            'agent_profile' => [
                'key' => $context['profile']?->key,
                'name' => $context['profile']?->name,
                'system_prompt' => $context['system_prompt_extension'],
            ],
            'agent_memory' => $context['memories'],
            'followup_policy' => $context['followup_policy'],
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'workspaces' => $materializedWorkspaces,
            'gateway' => [
                'base_url' => $gatewayBaseUrl,
                'tool_call_path' => '/api/v1/internal/agent-task-runs/'.$run->id.'/tool-calls',
                'llm_completion_path' => '/api/v1/internal/agent-task-runs/'.$run->id.'/llm-completions',
                'token' => $plainToken,
            ],
            'network_policy' => [
                'restrict_hosts' => $task->restrictsOutboundHosts(),
                'allowed_hosts' => $allowedOutboundHosts,
                'allowed_schemes' => ['http', 'https'],
            ],
            'sandbox' => [
                'persistent_workspace' => $persistentWorkspace,
                'synced_workspaces_dir' => '/workspace/synced-workspaces',
            ],
            'tools' => $this->toolExecutor->describeTools(
                $task,
                $user,
                $persistentWorkspace['container_path'] ?? $workspace,
                $run,
            ),
        ];

        File::put($inputDir.'/task.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $processResult = $this->runSandboxProcess($task, $run, $mountWorkspace, $persistentWorkspace);

        $run->update([
            'metadata' => [
                ...($run->metadata ?? []),
                'sandbox' => [
                    'workspace' => $workspace,
                    'mount_workspace' => $mountWorkspace,
                    'persistent_workspace' => $persistentWorkspace,
                    'synced_workspaces' => $materializedWorkspaces,
                    'stdout' => $processResult['stdout'] ?? '',
                    'stderr' => $processResult['stderr'] ?? '',
                    'exit_code' => $processResult['exit_code'] ?? null,
                ],
            ],
        ]);

        if (! ($processResult['successful'] ?? false)) {
            $stderr = trim((string) ($processResult['stderr'] ?? ''));
            $stdout = trim((string) ($processResult['stdout'] ?? ''));
            $details = $stderr !== '' ? $stderr : ($stdout !== '' ? $stdout : 'Sandbox container exited without stdout/stderr output.');

            throw new \RuntimeException(sprintf(
                'Sandbox execution failed (exit code %s): %s',
                (string) (($processResult['exit_code'] ?? null) ?? 'unknown'),
                $details,
            ));
        }

        $resultPath = $outputDir.'/result.json';
        if (! File::exists($resultPath)) {
            throw new \RuntimeException('Sandbox result file was not produced');
        }

        $result = json_decode((string) File::get($resultPath), true);
        if (! is_array($result)) {
            throw new \RuntimeException('Sandbox result file is invalid JSON');
        }

        if (($result['success'] ?? false) !== true) {
            throw new \RuntimeException((string) ($result['error'] ?? 'Sandbox task failed'));
        }

        $this->workspaceService->syncBackMaterializedWorkspaces($materializedWorkspaces);

        $memoryCandidates = is_array($result['memory_candidates'] ?? null) ? $result['memory_candidates'] : [];
        $ingestedMemories = $this->memoryIngestionService->ingest($task, $run, $memoryCandidates);

        $run->update([
            'metadata' => [
                ...($run->metadata ?? []),
                'sandbox_result' => [
                    'summary' => $result['summary'] ?? null,
                    'blocker' => $result['blocker'] ?? null,
                    'actions' => is_array($result['actions'] ?? null) ? $result['actions'] : [],
                    'findings' => is_array($result['findings'] ?? null) ? $result['findings'] : [],
                    'artifacts' => is_array($result['artifacts'] ?? null) ? $result['artifacts'] : [],
                    'plan' => is_array($result['plan'] ?? null) ? $result['plan'] : [],
                    'handoff' => is_array($result['handoff'] ?? null) ? $result['handoff'] : null,
                    'memory_candidates_count' => count($memoryCandidates),
                    'ingested_memories' => $ingestedMemories,
                ],
            ],
        ]);

        return (string) ($result['output'] ?? '');
    }

    protected function runSandboxProcess(AgentTask $task, AgentTaskRun $run, string $mountWorkspace, ?array $persistentWorkspace = null): array
    {
        $process = new Process($this->buildDockerCommand($task, $mountWorkspace, $persistentWorkspace));
        $process->setTimeout((float) $this->resolveSandboxTimeoutSeconds($task));
        $process->run(function (string $type, string $buffer): void {
            if ($buffer === '') {
                return;
            }

            if ($type === Process::ERR) {
                fwrite(STDERR, $buffer);

                return;
            }

            fwrite(STDOUT, $buffer);
        });

        return [
            'successful' => $process->isSuccessful(),
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
            'exit_code' => $process->getExitCode(),
        ];
    }

    private function buildDockerCommand(AgentTask $task, string $workspace, ?array $persistentWorkspace = null): array
    {
        $image = (string) ($task->effectiveSandboxProfile() ?: config('agent.agent_tasks.default_sandbox_image', 'spodial-agent-python:latest'));
        $network = $this->resolveSandboxNetwork();

        $command = [
            'docker',
            'run',
            '--rm',
            '--network',
            $network,
            '--tmpfs',
            '/tmp:rw,nosuid,size=512m',
            '--cpus',
            (string) config('agent.agent_tasks.sandbox_cpus', '2'),
            '--memory',
            (string) config('agent.agent_tasks.sandbox_memory', '2g'),
            '-v',
            $workspace.':/workspace',
        ];

        if ($persistentWorkspace !== null) {
            $command[] = '-v';
            $command[] = $persistentWorkspace['host_path'].':'.$persistentWorkspace['container_path'];
        }

        array_push(
            $command,
            '-w',
            '/workspace',
            $image,
            '--input',
            '/workspace/input/task.json',
            '--output',
            '/workspace/output/result.json',
            '--artifacts-dir',
            '/workspace/artifacts',
        );

        return $command;
    }

    private function resolveDockerWorkspaceMount(int $runId, string $workspace): string
    {
        $hostRunsRoot = trim((string) config('agent.agent_tasks.sandbox_host_runs_root', ''));
        if ($hostRunsRoot === '') {
            return $workspace;
        }

        return rtrim($hostRunsRoot, '/').'/'.$runId;
    }

    private function resolveSandboxTimeoutSeconds(AgentTask $task): int
    {
        $configuredDefault = (int) config('agent.agent_tasks.default_timeout_seconds', 1800);
        $configuredMax = (int) config('agent.agent_tasks.max_timeout_seconds', 3600);
        $requested = (int) ($task->metadata['timeout_seconds'] ?? $configuredDefault);

        return max(1, min(max(1, $configuredMax), $requested));
    }

    private function resolvePersistentWorkspace(AgentTask $task): ?array
    {
        if (! $task->usesPersistentSandboxWorkspace()) {
            return null;
        }

        $key = $task->persistentSandboxWorkspaceKey();
        if ($key === null || $key === '') {
            return null;
        }

        $root = trim((string) config('agent.agent_tasks.persistent_workspace_root', ''));
        $hostRoot = $root !== '' ? $root : storage_path('app/private/sandbox-cache');
        $sanitizedKey = preg_replace('/[^a-z0-9._-]+/i', '-', $key) ?: 'task-cache';

        return [
            'key' => $sanitizedKey,
            'host_path' => rtrim($hostRoot, '/').'/'.$sanitizedKey,
            'container_path' => '/workspace/persistent-cache',
        ];
    }

    private function resolveSandboxNetwork(): string
    {
        $configured = trim((string) config('agent.agent_tasks.sandbox_network', ''));
        if ($configured !== '') {
            return $configured;
        }

        $hostname = (string) gethostname();
        if ($hostname === '') {
            return 'bridge';
        }

        $output = (string) shell_exec(
            sprintf("docker inspect %s --format '{{range \$net, \$_ := .NetworkSettings.Networks}}{{\$net}}\n{{end}}' 2>/dev/null", escapeshellarg($hostname))
        );

        $skip = ['bridge', 'host', 'none', 'coolify'];
        $candidates = [];

        foreach (explode("\n", $output) as $net) {
            $net = trim($net);
            if ($net !== '' && ! in_array($net, $skip, true)) {
                $candidates[] = $net;
            }
        }

        return $candidates[0] ?? 'bridge';
    }
}
