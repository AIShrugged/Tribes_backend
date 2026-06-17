<?php

namespace Tests\Feature;

use App\Models\AgentTask;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Seeders\CommitReporterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CommitReporterScheduleTest extends TestCase
{
    use RefreshDatabase;

    private function seedWithUser(): AgentTask
    {
        $user = User::factory()->create(['email' => 'test@test.local']);
        $org = Organization::create(['name' => 'O', 'slug' => 'sched-org']);
        $user->organizations()->attach($org->id, ['role' => 'manager']); // @membership-allow direct attach (test)
        $this->seed(CommitReporterSeeder::class);

        return AgentTask::where('name', 'like', 'Commit Reporter%')->firstOrFail();
    }

    private function mskHm(CarbonInterface $t): string
    {
        return $t->copy()->setTimezone('Europe/Moscow')->format('H:i');
    }

    #[Test]
    public function seeds_the_task_enabled_and_pinned_to_the_next_8am_msk(): void
    {
        $task = $this->seedWithUser();

        $this->assertTrue((bool) $task->enabled);
        $this->assertSame(86400, (int) $task->interval_seconds);
        $this->assertSame('08:00', $this->mskHm($task->next_run_at));
        $this->assertTrue($task->next_run_at->isFuture());
    }

    #[Test]
    public function the_interval_chain_stays_pinned_to_8am_across_days(): void
    {
        $task = $this->seedWithUser();
        $base = $task->next_run_at;

        $c1 = $task->nextRunFrom($base);
        $c2 = $task->nextRunFrom($c1);

        $this->assertSame('08:00', $this->mskHm($c1));
        $this->assertSame('08:00', $this->mskHm($c2));
        $this->assertEqualsWithDelta(86400, abs($c1->diffInSeconds($base)), 1);
    }

    #[Test]
    public function reseed_reanchors_and_enables_a_paused_misaligned_task(): void
    {
        $task = $this->seedWithUser();
        // simulate a paused task pinned to the wrong time of day
        $task->update(['enabled' => false, 'next_run_at' => now()->setTime(15, 30, 0)]);

        $this->seed(CommitReporterSeeder::class); // reconcile path re-anchors + enables

        $task->refresh();
        $this->assertTrue((bool) $task->enabled);
        $this->assertSame('08:00', $this->mskHm($task->next_run_at));
    }
}
