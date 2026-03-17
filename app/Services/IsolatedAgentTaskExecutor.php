<?php

namespace App\Services;

use App\Models\AgentTask;
use App\Models\AgentTaskRun;
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
        if (is_string($gatewayHost) && $gatewayHost !== '') {
            $allowedOutboundHosts[] = $gatewayHost;
        }
        $allowedOutboundHosts = array_values(array_unique($allowedOutboundHosts));
        $workspace = storage_path('app/private/sandbox-runs/'.$run->id);
        $mountWorkspace = $this->resolveDockerWorkspaceMount($run->id, $workspace);
        $inputDir = $workspace.'/input';
        $outputDir = $workspace.'/output';
        $artifactsDir = $workspace.'/artifacts';

        File::ensureDirectoryExists($inputDir);
        File::ensureDirectoryExists($outputDir);
        File::ensureDirectoryExists($artifactsDir);
        @chmod($workspace, 0777);
        @chmod($inputDir, 0777);
        @chmod($outputDir, 0777);
        @chmod($artifactsDir, 0777);

        $payload = [
            'task' => [
                'id' => $task->id,
                'run_id' => $run->id,
                'name' => $task->name,
                'prompt' => $context['user_prompt'],
                'sandbox_profile' => $context['sandbox_profile'],
                'allowed_tools' => $context['allowed_tools'],
                'allowed_outbound_hosts' => $allowedOutboundHosts,
                'max_iterations' => (int) ($task->metadata['max_iterations'] ?? 8),
                'input_payload' => $context['input_payload'],
            ],
            'agent_profile' => [
                'key' => $context['profile']?->key,
                'name' => $context['profile']?->name,
                'system_prompt' => $context['system_prompt_extension'],
            ],
            'agent_memory' => $context['memories'],
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'gateway' => [
                'base_url' => $gatewayBaseUrl,
                'tool_call_path' => '/api/v1/internal/agent-task-runs/'.$run->id.'/tool-calls',
                'llm_completion_path' => '/api/v1/internal/agent-task-runs/'.$run->id.'/llm-completions',
                'token' => $plainToken,
            ],
            'network_policy' => [
                'allowed_hosts' => $allowedOutboundHosts,
                'allowed_schemes' => ['http', 'https'],
            ],
            'tools' => $this->toolExecutor->describeTools($task, $user),
        ];

        File::put($inputDir.'/task.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $process = new Process($this->buildDockerCommand($task, $mountWorkspace));
        $process->setTimeout((float) max(1, (int) ($task->metadata['timeout_seconds'] ?? 300)));
        $process->run();

        $run->update([
            'metadata' => [
                ...($run->metadata ?? []),
                'sandbox' => [
                    'workspace' => $workspace,
                    'mount_workspace' => $mountWorkspace,
                    'stdout' => $process->getOutput(),
                    'stderr' => $process->getErrorOutput(),
                    'exit_code' => $process->getExitCode(),
                ],
            ],
        ]);

        if (! $process->isSuccessful()) {
            $stderr = trim($process->getErrorOutput());
            $stdout = trim($process->getOutput());
            $details = $stderr !== '' ? $stderr : ($stdout !== '' ? $stdout : 'Sandbox container exited without stdout/stderr output.');

            throw new \RuntimeException(sprintf(
                'Sandbox execution failed (exit code %s): %s',
                (string) ($process->getExitCode() ?? 'unknown'),
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

        $memoryCandidates = is_array($result['memory_candidates'] ?? null) ? $result['memory_candidates'] : [];
        $ingestedMemories = $this->memoryIngestionService->ingest($task, $run, $memoryCandidates);

        $run->update([
            'metadata' => [
                ...($run->metadata ?? []),
                'sandbox_result' => [
                    'summary' => $result['summary'] ?? null,
                    'memory_candidates_count' => count($memoryCandidates),
                    'ingested_memories' => $ingestedMemories,
                ],
            ],
        ]);

        return (string) ($result['output'] ?? '');
    }

    private function buildDockerCommand(AgentTask $task, string $workspace): array
    {
        $image = (string) ($task->effectiveSandboxProfile() ?: config('agent.agent_tasks.default_sandbox_image', 'spodial-agent-python:latest'));
        $network = trim((string) config('agent.agent_tasks.sandbox_network', 'bridge')) ?: 'bridge';

        return [
            'docker',
            'run',
            '--rm',
            '--network',
            $network,
            '--read-only',
            '--tmpfs',
            '/tmp:rw,noexec,nosuid,size=128m',
            '--cpus',
            (string) config('agent.agent_tasks.sandbox_cpus', '1'),
            '--memory',
            (string) config('agent.agent_tasks.sandbox_memory', '512m'),
            '-v',
            $workspace.':/workspace',
            '-w',
            '/workspace',
            $image,
            '--input',
            '/workspace/input/task.json',
            '--output',
            '/workspace/output/result.json',
            '--artifacts-dir',
            '/workspace/artifacts',
        ];
    }

    private function resolveDockerWorkspaceMount(int $runId, string $workspace): string
    {
        $hostRunsRoot = trim((string) config('agent.agent_tasks.sandbox_host_runs_root', ''));
        if ($hostRunsRoot === '') {
            return $workspace;
        }

        return rtrim($hostRunsRoot, '/').'/'.$runId;
    }
}
