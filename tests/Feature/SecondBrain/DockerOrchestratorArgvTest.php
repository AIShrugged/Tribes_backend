<?php

namespace Tests\Feature\SecondBrain;

use App\Models\SecondBrainInstance;
use App\Services\SecondBrain\DockerNetworkResolver;
use App\Services\SecondBrain\DockerOrchestrator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DockerOrchestratorArgvTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Pin the network/gateway so the resolver never shells out to docker.
        config([
            'second_brain.network' => 'test-net',
            'second_brain.internal_base_url' => 'http://nginx-test',
            'second_brain.image' => 'spodial-second-brain:test',
            'second_brain.github_token' => '',
        ]);
    }

    private function orchestrator(array &$calls): DockerOrchestrator
    {
        return new class($calls) extends DockerOrchestrator {
            public function __construct(private array &$captured)
            {
                parent::__construct(new DockerNetworkResolver());
            }

            protected function runDocker(array $args, array $env = [], int $timeout = 120): array
            {
                $this->captured[] = ['args' => $args, 'env' => $env];

                return ['ok' => true, 'stdout' => '', 'stderr' => '', 'exit' => 0];
            }
        };
    }

    private function buildInstance(string $authType, string $cred): SecondBrainInstance
    {
        $instance = new SecondBrainInstance([
            'organization_id' => 42,
            'claude_auth_type' => $authType,
            'container_name' => SecondBrainInstance::containerNameFor(42),
            'volume_name' => SecondBrainInstance::volumeNameFor(42),
        ]);
        // encrypted casts on set; read back plaintext via accessor.
        $instance->token_ciphertext = 'tribes-token-abc';
        $instance->claude_auth_ciphertext = $cred;

        return $instance;
    }

    #[Test]
    public function run_container_builds_the_expected_docker_run_argv_for_oauth(): void
    {
        $calls = [];
        $this->orchestrator($calls)->runContainer($this->buildInstance(SecondBrainInstance::AUTH_OAUTH, 'oauth-cred'));

        $run = collect($calls)->first(fn ($c) => ($c['args'][1] ?? null) === 'run');
        $this->assertNotNull($run, 'a `docker run` call was captured');
        $args = $run['args'];

        $this->assertContainsSubsequence(['--name', 'second-brain-org42'], $args);
        $this->assertContainsSubsequence(['--network', 'test-net'], $args);
        $this->assertContainsSubsequence(['--label', 'com.tribes.secondbrain=1'], $args);
        $this->assertContainsSubsequence(['--label', 'com.tribes.secondbrain.org=42'], $args);
        $this->assertContainsSubsequence(['-v', 'second-brain-state-org42:/state'], $args);
        $this->assertContainsSubsequence(['--env', 'TRIBESMCP_TOKEN'], $args);
        $this->assertContainsSubsequence(['--env', 'CLAUDE_CODE_OAUTH_TOKEN'], $args);
        $this->assertNotContains('ANTHROPIC_API_KEY', $args);
        $this->assertContainsSubsequence(['-e', 'BRAIN_API_URL=http://nginx-test/api/v1'], $args);
        $this->assertSame('spodial-second-brain:test', end($args));

        // Secrets travel via the child env, not argv.
        $this->assertSame('tribes-token-abc', $run['env']['TRIBESMCP_TOKEN']);
        $this->assertSame('oauth-cred', $run['env']['CLAUDE_CODE_OAUTH_TOKEN']);
        $this->assertArrayNotHasKey('ANTHROPIC_API_KEY', $run['env']);
    }

    #[Test]
    public function run_container_uses_anthropic_api_key_env_for_api_key_auth(): void
    {
        $calls = [];
        $this->orchestrator($calls)->runContainer($this->buildInstance(SecondBrainInstance::AUTH_API_KEY, 'sk-ant-key'));

        $run = collect($calls)->first(fn ($c) => ($c['args'][1] ?? null) === 'run');
        $args = $run['args'];

        $this->assertContainsSubsequence(['--env', 'ANTHROPIC_API_KEY'], $args);
        $this->assertNotContains('CLAUDE_CODE_OAUTH_TOKEN', $args);
        $this->assertSame('sk-ant-key', $run['env']['ANTHROPIC_API_KEY']);
        $this->assertArrayNotHasKey('CLAUDE_CODE_OAUTH_TOKEN', $run['env']);
    }

    /** Assert $needle appears as consecutive elements somewhere in $haystack. */
    private function assertContainsSubsequence(array $needle, array $haystack): void
    {
        $found = false;
        $n = count($needle);
        for ($i = 0, $len = count($haystack); $i + $n <= $len; $i++) {
            if (array_slice($haystack, $i, $n) === $needle) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'expected ['.implode(' ', $needle).'] in docker argv');
    }
}
