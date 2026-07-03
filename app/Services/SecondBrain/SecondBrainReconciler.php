<?php

namespace App\Services\SecondBrain;

use App\Models\SecondBrainInstance;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Converges Docker to the desired state in `second_brain_instances`: starts a
 * container for every enabled org, stops it for disabled orgs (keeping the
 * volume), recreates it when the org's Claude credential changed, and reaps
 * orphaned containers. Idempotent and crash-safe — it only acts on the delta
 * between the DB and observed `docker` state.
 *
 * The docker boundary is injected (DockerOrchestrator) so this is unit-testable
 * without a real daemon.
 */
class SecondBrainReconciler
{
    public function __construct(private readonly DockerOrchestrator $orchestrator) {}

    /**
     * Run one convergence pass. When $onlyOrgId is given, only that org's
     * instance is reconciled and orphan reaping is skipped.
     *
     * @return array{started: int, stopped: int, errors: int, skipped: int}
     */
    public function reconcile(?int $onlyOrgId = null): array
    {
        $this->orchestrator->ensureImageBuilt();

        $query = SecondBrainInstance::query();
        if ($onlyOrgId !== null) {
            $query->where('organization_id', $onlyOrgId);
        }
        $instances = $query->get();

        $cap = max(0, (int) config('second_brain.max_containers', 20));
        $summary = ['started' => 0, 'stopped' => 0, 'errors' => 0, 'skipped' => 0];
        $activeCount = 0;

        foreach ($instances as $instance) {
            try {
                $activeCount = $this->reconcileInstance($instance, $cap, $activeCount, $summary);
            } catch (\Throwable $e) {
                $summary['errors']++;
                $instance->forceFill([
                    'status' => SecondBrainInstance::STATUS_ERROR,
                    'last_error' => Str::limit($e->getMessage(), 1000),
                    'last_reconciled_at' => now(),
                ])->save();
                Log::warning('second-brain reconcile failed', [
                    'organization_id' => $instance->organization_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($onlyOrgId === null) {
            $summary['stopped'] += $this->reapOrphans($instances);
        }

        return $summary;
    }

    private function reconcileInstance(SecondBrainInstance $instance, int $cap, int $activeCount, array &$summary): int
    {
        $name = $instance->container_name ?: SecondBrainInstance::containerNameFor((int) $instance->organization_id);
        $dockerStatus = $this->orchestrator->inspectStatus($name);
        $isRunning = $dockerStatus === 'running';

        if (! $instance->enabled) {
            if ($dockerStatus !== null) {
                $this->orchestrator->stopContainer($name);
                $summary['stopped']++;
            }
            $instance->forceFill([
                'status' => SecondBrainInstance::STATUS_DISABLED,
                'last_reconciled_at' => now(),
            ])->save();

            return $activeCount;
        }

        // Enabled: (re)start when not running, the credential changed, or the
        // container runs a stale image (a redeploy rebuilt the sidecar).
        $needsStart = ! $isRunning
            || $this->credentialChangedSinceStart($instance)
            || ($isRunning && $this->imageChanged($name));

        if ($needsStart) {
            if ($activeCount >= $cap) {
                $summary['skipped']++;
                $instance->forceFill([
                    'status' => SecondBrainInstance::STATUS_ERROR,
                    'last_error' => "capacity: max_containers={$cap} reached; not started",
                    'last_reconciled_at' => now(),
                ])->save();
                Log::warning('second-brain reconcile skipped (capacity)', [
                    'organization_id' => $instance->organization_id,
                    'cap' => $cap,
                ]);

                return $activeCount;
            }

            $this->orchestrator->runContainer($instance);
            $summary['started']++;
            $instance->forceFill([
                'status' => SecondBrainInstance::STATUS_RUNNING,
                'last_started_at' => now(),
                'last_error' => null,
                'last_reconciled_at' => now(),
            ])->save();

            return $activeCount + 1;
        }

        // Already running and healthy.
        $instance->forceFill([
            'status' => SecondBrainInstance::STATUS_RUNNING,
            'last_reconciled_at' => now(),
        ])->save();

        return $activeCount + 1;
    }

    /** True when the running container was built from a different image than the current tag. */
    private function imageChanged(string $containerName): bool
    {
        $current = $this->orchestrator->currentImageId();
        $running = $this->orchestrator->containerImageId($containerName);

        return $current !== null && $running !== null && $current !== $running;
    }

    private function credentialChangedSinceStart(SecondBrainInstance $instance): bool
    {
        if ($instance->credentials_changed_at === null) {
            return false;
        }
        if ($instance->last_started_at === null) {
            return true;
        }

        return $instance->credentials_changed_at->greaterThan($instance->last_started_at);
    }

    /**
     * Remove managed containers that no enabled instance claims (disabled orgs,
     * deleted orgs, drift). Their volumes are kept.
     */
    private function reapOrphans(\Illuminate\Support\Collection $instances): int
    {
        $enabledOrgIds = $instances
            ->where('enabled', true)
            ->pluck('organization_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $reaped = 0;
        foreach ($this->orchestrator->listManagedContainerNames() as $name) {
            $orgId = $this->orgIdFromContainerName($name);
            if ($orgId === null || ! in_array($orgId, $enabledOrgIds, true)) {
                $this->orchestrator->stopContainer($name);
                $reaped++;
            }
        }

        return $reaped;
    }

    private function orgIdFromContainerName(string $name): ?int
    {
        if (preg_match('/^second-brain-org(\d+)$/', $name, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }
}
