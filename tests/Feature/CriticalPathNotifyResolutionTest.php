<?php

namespace Tests\Feature;

use App\Jobs\NotifyCriticalPathJob;
use App\Models\CriticalPathGraph;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Team;
use App\Services\CriticalPath\CriticalPathNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class CriticalPathNotifyResolutionTest extends TestCase
{
    use RefreshDatabase;

    private function resolve(CriticalPathGraph $graph): ?int
    {
        $service = $this->app->make(CriticalPathNotificationService::class);
        $method = new ReflectionMethod($service, 'resolveNotificationTeamId');
        $method->setAccessible(true);

        return $method->invoke($service, $graph);
    }

    private function signature(Collection $nodes): string
    {
        $service = $this->app->make(CriticalPathNotificationService::class);
        $method = new ReflectionMethod($service, 'criticalPathSignature');
        $method->setAccessible(true);

        return $method->invoke($service, $nodes);
    }

    #[Test]
    public function org_level_graph_resolves_to_default_team(): void
    {
        $org = Organization::create(['name' => 'CP Org', 'slug' => 'cp-org']);
        $this->assertNotNull($org->defaultTeam);

        $graph = CriticalPathGraph::create([
            'organization_id' => $org->id, 'team_id' => null, 'status' => 'ready',
        ]);

        $this->assertSame($org->defaultTeam->id, $this->resolve($graph));
    }

    #[Test]
    public function default_team_scoped_graph_is_skipped_to_avoid_duplicate(): void
    {
        $org = Organization::create(['name' => 'CP Org 2', 'slug' => 'cp-org-2']);
        $graph = CriticalPathGraph::create([
            'organization_id' => $org->id, 'team_id' => $org->defaultTeam->id, 'status' => 'ready',
        ]);

        $this->assertNull($this->resolve($graph));
    }

    #[Test]
    public function real_team_scoped_graph_uses_its_own_team(): void
    {
        $org = Organization::create(['name' => 'CP Org 3', 'slug' => 'cp-org-3']);
        $methodology = Methodology::query()->where('is_default', true)->first()
            ?? Methodology::create(['name' => 'M', 'text' => 't', 'scheme' => '{}', 'is_default' => true]);
        $realTeam = Team::create([
            'organization_id' => $org->id, 'methodology_id' => $methodology->id,
            'name' => 'Squad', 'slug' => 'squad-'.$org->id, 'is_default' => false,
        ]);

        $graph = CriticalPathGraph::create([
            'organization_id' => $org->id, 'team_id' => $realTeam->id, 'status' => 'ready',
        ]);

        $this->assertSame($realTeam->id, $this->resolve($graph));
    }

    #[Test]
    public function signature_is_order_independent_and_changes_with_critical_set(): void
    {
        $a = collect([
            (object) ['issue_id' => 1, 'early_finish' => 5],
            (object) ['issue_id' => 2, 'early_finish' => 10],
        ]);
        $aReordered = collect([
            (object) ['issue_id' => 2, 'early_finish' => 10],
            (object) ['issue_id' => 1, 'early_finish' => 5],
        ]);
        $b = collect([
            (object) ['issue_id' => 1, 'early_finish' => 5],
            (object) ['issue_id' => 3, 'early_finish' => 10],
        ]);

        $this->assertSame($this->signature($a), $this->signature($aReordered));
        $this->assertNotSame($this->signature($a), $this->signature($b));
    }

    #[Test]
    public function notify_team_does_not_mark_signature_when_no_settings(): void
    {
        $org = Organization::create(['name' => 'CP Org 4', 'slug' => 'cp-org-4']);
        $graph = CriticalPathGraph::create([
            'organization_id' => $org->id, 'team_id' => null, 'status' => 'ready',
        ]);

        $this->app->make(CriticalPathNotificationService::class)->notifyTeam($graph);

        $this->assertNull($graph->fresh()->last_notified_signature);
    }

    #[Test]
    public function notify_critical_path_job_is_single_attempt(): void
    {
        $this->assertSame(1, (new NotifyCriticalPathJob(1))->tries);
    }
}
