<?php

namespace App\Services\SecondBrain;

use App\Models\SecondBrainInstance;
use Symfony\Component\Process\Process;

/**
 * Thin wrapper over the `docker` CLI (same approach as
 * App\Services\IsolatedAgentTaskExecutor) that the reconciler uses to run one
 * Claude Code container per enabled organization.
 *
 * Secrets (the org's TRIBESMCP token + Claude credential) are passed through the
 * child process environment with `--env NAME` (no value in argv) so they never
 * appear in the host process list.
 */
class DockerOrchestrator
{
    /** Docker label marking every container this orchestrator manages. */
    public const LABEL = 'com.tribes.secondbrain';

    private bool $imageEnsured = false;

    public function __construct(private readonly DockerNetworkResolver $network) {}

    /**
     * Build the sidecar image once per process. Rebuilding on each fresh
     * orchestrator process means a redeploy picks up sidecar source changes
     * (docker layer cache keeps it fast when nothing changed); an unchanged
     * rebuild yields the same image id, so no container is needlessly recreated.
     */
    public function ensureImageBuilt(): void
    {
        if ($this->imageEnsured) {
            return;
        }

        $context = (string) config('second_brain.build_context', base_path('docker/second-brain'));
        $this->mustRunDocker(['docker', 'build', '-t', $this->image(), $context], [], 900);
        $this->imageEnsured = true;
    }

    /** Image id (sha256:…) of the current sidecar image, or null if missing. */
    public function currentImageId(): ?string
    {
        $result = $this->runDocker(['docker', 'image', 'inspect', '-f', '{{.Id}}', $this->image()]);

        return $result['ok'] && $result['stdout'] !== '' ? $result['stdout'] : null;
    }

    /** Image id the given container was created from, or null if absent. */
    public function containerImageId(string $containerName): ?string
    {
        $result = $this->runDocker(['docker', 'inspect', '-f', '{{.Image}}', $containerName]);

        return $result['ok'] && $result['stdout'] !== '' ? $result['stdout'] : null;
    }

    /**
     * (Re)create and start the org's container on its own named volume. Removes
     * any stale same-named container first; the volume is preserved.
     */
    public function runContainer(SecondBrainInstance $instance): void
    {
        $orgId = (int) $instance->organization_id;
        $name = $instance->container_name ?: SecondBrainInstance::containerNameFor($orgId);
        $volume = $instance->volume_name ?: SecondBrainInstance::volumeNameFor($orgId);
        $token = (string) $instance->token_ciphertext;
        $cred = (string) $instance->claude_auth_ciphertext;

        if ($token === '' || $cred === '') {
            throw new \RuntimeException("second-brain org {$orgId}: missing MCP token or Claude credential");
        }

        // Replace any stale container; ensure the state volume exists.
        $this->runDocker(['docker', 'rm', '-f', $name]);
        $this->mustRunDocker(['docker', 'volume', 'create', $volume]);

        $claudeEnvVar = $instance->claude_auth_type === SecondBrainInstance::AUTH_API_KEY
            ? 'ANTHROPIC_API_KEY'
            : 'CLAUDE_CODE_OAUTH_TOKEN';

        $env = [
            'TRIBESMCP_TOKEN' => $token,
            $claudeEnvVar => $cred,
        ];

        $allowStatusWrites = filter_var(config('second_brain.allow_status_writes'), FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
        $gateway = $this->network->gatewayBaseUrl();

        $args = [
            'docker', 'run', '-d',
            '--name', $name,
            '--restart', 'unless-stopped',
            '--network', $this->network->network(),
            '--label', self::LABEL.'=1',
            '--label', self::LABEL.'.org='.$orgId,
            '--cpus', (string) config('second_brain.cpus', '1'),
            '--memory', (string) config('second_brain.memory', '1g'),
            '--pids-limit', (string) (int) config('second_brain.pids_limit', 512),
            '--env', 'TRIBESMCP_TOKEN',
            '--env', $claudeEnvVar,
        ];

        $githubToken = (string) config('second_brain.github_token', '');
        if ($githubToken !== '') {
            $env['GITHUB_TOKEN'] = $githubToken;
            $args[] = '--env';
            $args[] = 'GITHUB_TOKEN';
        }

        array_push(
            $args,
            '-e', 'BRAIN_API_URL='.$gateway.'/api/v1',
            '-e', 'BRAIN_MCP_URL='.$gateway.'/mcp',
            '-e', 'BRAIN_ALLOW_STATUS_WRITES='.$allowStatusWrites,
            '-e', 'BRAIN_SESSION_RESTART_DELAY='.(string) (int) config('second_brain.restart_delay', 60),
            '-v', $volume.':/state',
            $this->image(),
        );

        $this->mustRunDocker($args, $env);
    }

    /** Remove the org's container (its state volume is kept). */
    public function stopContainer(string $containerName): void
    {
        $this->runDocker(['docker', 'rm', '-f', $containerName]);
    }

    /** Container state (running|exited|…) or null if it does not exist. */
    public function inspectStatus(string $containerName): ?string
    {
        $result = $this->runDocker(['docker', 'inspect', '-f', '{{.State.Status}}', $containerName]);
        if (! $result['ok']) {
            return null;
        }

        return $result['stdout'] !== '' ? $result['stdout'] : null;
    }

    /** Names of every container this orchestrator manages (for orphan reaping). */
    public function listManagedContainerNames(): array
    {
        $result = $this->runDocker([
            'docker', 'ps', '-a',
            '--filter', 'label='.self::LABEL.'=1',
            '--format', '{{.Names}}',
        ]);

        if (! $result['ok'] || $result['stdout'] === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode("\n", $result['stdout']))));
    }

    private function image(): string
    {
        return (string) config('second_brain.image', 'spodial-second-brain:latest');
    }

    /**
     * Runs a docker command. Protected so tests can override the docker boundary
     * (capture argv/env) without a real daemon.
     *
     * @return array{ok: bool, stdout: string, stderr: string, exit: int|null}
     */
    protected function runDocker(array $args, array $env = [], int $timeout = 120): array
    {
        $process = new Process($args, null, $env !== [] ? $env : null);
        $process->setTimeout($timeout);
        $process->run();

        return [
            'ok' => $process->isSuccessful(),
            'stdout' => trim($process->getOutput()),
            'stderr' => trim($process->getErrorOutput()),
            'exit' => $process->getExitCode(),
        ];
    }

    protected function mustRunDocker(array $args, array $env = [], int $timeout = 120): string
    {
        $result = $this->runDocker($args, $env, $timeout);
        if (! $result['ok']) {
            throw new \RuntimeException($result['stderr'] !== '' ? $result['stderr'] : ('docker exited '.$result['exit']));
        }

        return $result['stdout'];
    }
}
