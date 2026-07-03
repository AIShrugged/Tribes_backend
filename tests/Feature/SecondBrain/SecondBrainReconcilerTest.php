<?php

namespace Tests\Feature\SecondBrain;

use App\Models\Organization;
use App\Models\SecondBrainInstance;
use App\Services\SecondBrain\DockerNetworkResolver;
use App\Services\SecondBrain\DockerOrchestrator;
use App\Services\SecondBrain\SecondBrainReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fake orchestrator that records actions and simulates docker state, so the
 * converge logic is testable without a real daemon.
 */
class FakeOrchestrator extends DockerOrchestrator
{
    /** @var array<string, string> container name => state */
    public array $states = [];
    public array $started = [];
    public array $stopped = [];
    public array $managed = [];
    public ?string $currentImage = 'img-v1';
    /** @var array<string, string> container name => image id */
    public array $containerImages = [];

    public function __construct()
    {
        parent::__construct(new DockerNetworkResolver());
    }

    public function ensureImageBuilt(): void {}

    public function currentImageId(): ?string
    {
        return $this->currentImage;
    }

    public function containerImageId(string $containerName): ?string
    {
        return $this->containerImages[$containerName] ?? null;
    }

    public function runContainer(SecondBrainInstance $instance): void
    {
        $name = $instance->container_name;
        $this->started[] = $name;
        $this->states[$name] = 'running';
    }

    public function stopContainer(string $containerName): void
    {
        $this->stopped[] = $containerName;
        unset($this->states[$containerName]);
    }

    public function inspectStatus(string $containerName): ?string
    {
        return $this->states[$containerName] ?? null;
    }

    public function listManagedContainerNames(): array
    {
        return $this->managed;
    }
}

class SecondBrainReconcilerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
    }

    private function instanceFor(int $orgId, bool $enabled, string $status): SecondBrainInstance
    {
        $org = Organization::create(['name' => "Org {$orgId}", 'slug' => 'org-'.$orgId.'-'.uniqid()]);

        return SecondBrainInstance::create([
            'organization_id' => $org->id,
            'enabled' => $enabled,
            'status' => $status,
            'container_name' => SecondBrainInstance::containerNameFor($org->id),
            'volume_name' => SecondBrainInstance::volumeNameFor($org->id),
            'claude_auth_type' => SecondBrainInstance::AUTH_OAUTH,
            'claude_auth_ciphertext' => 'cred',
            'token_ciphertext' => 'tok',
        ]);
    }

    #[Test]
    public function it_starts_an_enabled_instance_that_is_not_running(): void
    {
        $instance = $this->instanceFor(1, true, SecondBrainInstance::STATUS_PENDING);
        $fake = new FakeOrchestrator();

        $summary = (new SecondBrainReconciler($fake))->reconcile();

        $this->assertSame([$instance->container_name], $fake->started);
        $this->assertSame(1, $summary['started']);
        $this->assertSame(SecondBrainInstance::STATUS_RUNNING, $instance->fresh()->status);
        $this->assertNotNull($instance->fresh()->last_started_at);
    }

    #[Test]
    public function it_stops_a_disabled_instance_whose_container_exists(): void
    {
        $instance = $this->instanceFor(2, false, SecondBrainInstance::STATUS_STOPPING);
        $fake = new FakeOrchestrator();
        $fake->states[$instance->container_name] = 'running';

        $summary = (new SecondBrainReconciler($fake))->reconcile();

        $this->assertContains($instance->container_name, $fake->stopped);
        $this->assertSame(SecondBrainInstance::STATUS_DISABLED, $instance->fresh()->status);
    }

    #[Test]
    public function it_enforces_the_max_containers_cap(): void
    {
        config(['second_brain.max_containers' => 0]);
        $instance = $this->instanceFor(3, true, SecondBrainInstance::STATUS_PENDING);
        $fake = new FakeOrchestrator();

        $summary = (new SecondBrainReconciler($fake))->reconcile();

        $this->assertSame([], $fake->started);
        $this->assertSame(1, $summary['skipped']);
        $fresh = $instance->fresh();
        $this->assertSame(SecondBrainInstance::STATUS_ERROR, $fresh->status);
        $this->assertStringContainsString('capacity', $fresh->last_error);
    }

    #[Test]
    public function it_reaps_an_orphaned_container(): void
    {
        // No instance claims org 999, but a managed container exists for it.
        $fake = new FakeOrchestrator();
        $fake->managed = ['second-brain-org999'];
        $fake->states['second-brain-org999'] = 'running';

        $summary = (new SecondBrainReconciler($fake))->reconcile();

        $this->assertContains('second-brain-org999', $fake->stopped);
        $this->assertGreaterThanOrEqual(1, $summary['stopped']);
    }

    #[Test]
    public function it_recreates_a_running_container_after_a_credential_change(): void
    {
        $instance = $this->instanceFor(4, true, SecondBrainInstance::STATUS_RUNNING);
        $instance->forceFill([
            'last_started_at' => now()->subHour(),
            'credentials_changed_at' => now(),
        ])->save();

        $fake = new FakeOrchestrator();
        $fake->states[$instance->container_name] = 'running';

        (new SecondBrainReconciler($fake))->reconcile();

        // credentials_changed_at > last_started_at → recreate.
        $this->assertContains($instance->container_name, $fake->started);
    }

    #[Test]
    public function it_recreates_a_running_container_on_a_stale_image(): void
    {
        $instance = $this->instanceFor(5, true, SecondBrainInstance::STATUS_RUNNING);
        $instance->forceFill(['last_started_at' => now()])->save();

        $fake = new FakeOrchestrator();
        $fake->states[$instance->container_name] = 'running';
        $fake->currentImage = 'img-new';
        $fake->containerImages[$instance->container_name] = 'img-old';

        (new SecondBrainReconciler($fake))->reconcile();

        $this->assertContains($instance->container_name, $fake->started);
    }

    #[Test]
    public function it_leaves_a_healthy_up_to_date_running_container_alone(): void
    {
        $instance = $this->instanceFor(6, true, SecondBrainInstance::STATUS_RUNNING);
        $instance->forceFill(['last_started_at' => now()])->save();

        $fake = new FakeOrchestrator();
        $fake->states[$instance->container_name] = 'running';
        $fake->currentImage = 'img-same';
        $fake->containerImages[$instance->container_name] = 'img-same';

        (new SecondBrainReconciler($fake))->reconcile();

        $this->assertSame([], $fake->started);
        $this->assertSame([], $fake->stopped);
    }
}
