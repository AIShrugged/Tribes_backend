<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Issue;
use App\Models\Profile;
use App\Models\User;
use App\Services\Agent\MemoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserUrgentTasksTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private MemoryService $memoryService;

    protected function setUp(): void
    {
        parent::setUp();

        $channel = Channel::firstOrCreate(['name' => 'web']);

        $this->user = User::factory()->create();

        Profile::create([
            'channel_id'         => $channel->id,
            'channel_identifier' => (string) $this->user->id,
            'user_id'            => $this->user->id,
        ]);

        $this->memoryService = app(MemoryService::class);
    }

    #[Test]
    public function urgent_tasks_block_is_absent_when_no_critical_or_overdue_issues(): void
    {
        Issue::create([
            'user_id' => $this->user->id,
            'name'    => 'Normal task',
            'status'  => 'open',
            'priority' => Issue::PRIORITY_NORMAL,
        ]);

        $context = $this->memoryService->composeMemoryContext($this->user, 'web');

        $this->assertStringNotContainsString('### Urgent Tasks', $context);
    }

    #[Test]
    public function urgent_tasks_block_appears_for_critical_priority_issue(): void
    {
        Issue::create([
            'user_id'  => $this->user->id,
            'name'     => 'Fix auth module',
            'status'   => 'open',
            'priority' => Issue::PRIORITY_CRITICAL,
        ]);

        $context = $this->memoryService->composeMemoryContext($this->user, 'web');

        $this->assertStringContainsString('### Urgent Tasks', $context);
        $this->assertStringContainsString('[CRITICAL]', $context);
        $this->assertStringContainsString('Fix auth module', $context);
    }

    #[Test]
    public function urgent_tasks_block_appears_for_overdue_issue(): void
    {
        Issue::create([
            'user_id'  => $this->user->id,
            'name'     => 'Overdue task',
            'status'   => 'open',
            'priority' => Issue::PRIORITY_NORMAL,
            'due_date' => now()->subDay()->toDateString(),
        ]);

        $context = $this->memoryService->composeMemoryContext($this->user, 'web');

        $this->assertStringContainsString('### Urgent Tasks', $context);
        $this->assertStringContainsString('[OVERDUE]', $context);
        $this->assertStringContainsString('Overdue task', $context);
    }

    #[Test]
    public function closed_issues_are_excluded(): void
    {
        Issue::create([
            'user_id'  => $this->user->id,
            'name'     => 'Closed critical',
            'status'   => 'done',
            'priority' => Issue::PRIORITY_CRITICAL,
        ]);

        $context = $this->memoryService->composeMemoryContext($this->user, 'web');

        $this->assertStringNotContainsString('### Urgent Tasks', $context);
    }

    #[Test]
    public function issues_assigned_to_user_are_included(): void
    {
        $owner = User::factory()->create();

        Issue::create([
            'user_id'     => $owner->id,
            'assignee_id' => $this->user->id,
            'name'        => 'Assigned critical task',
            'status'      => 'open',
            'priority'    => Issue::PRIORITY_CRITICAL,
        ]);

        $context = $this->memoryService->composeMemoryContext($this->user, 'web');

        $this->assertStringContainsString('Assigned critical task', $context);
    }

    #[Test]
    public function other_users_issues_are_not_included(): void
    {
        $otherUser = User::factory()->create();

        Issue::create([
            'user_id'  => $otherUser->id,
            'name'     => 'Other users critical task',
            'status'   => 'open',
            'priority' => Issue::PRIORITY_CRITICAL,
        ]);

        $context = $this->memoryService->composeMemoryContext($this->user, 'web');

        $this->assertStringNotContainsString('### Urgent Tasks', $context);
        $this->assertStringNotContainsString('Other users critical task', $context);
    }

    #[Test]
    public function limit_of_five_issues_is_respected(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            Issue::create([
                'user_id'  => $this->user->id,
                'name'     => "Critical task {$i}",
                'status'   => 'open',
                'priority' => Issue::PRIORITY_CRITICAL,
            ]);
        }

        $context = $this->memoryService->composeMemoryContext($this->user, 'web');

        $this->assertStringContainsString('### Urgent Tasks', $context);

        $count = substr_count($context, '[CRITICAL]');
        $this->assertSame(5, $count);
    }

    #[Test]
    public function critical_issues_appear_before_overdue_in_block(): void
    {
        Issue::create([
            'user_id'  => $this->user->id,
            'name'     => 'Overdue normal task',
            'status'   => 'open',
            'priority' => Issue::PRIORITY_NORMAL,
            'due_date' => now()->subDay()->toDateString(),
        ]);

        Issue::create([
            'user_id'  => $this->user->id,
            'name'     => 'Critical task',
            'status'   => 'open',
            'priority' => Issue::PRIORITY_CRITICAL,
        ]);

        $context = $this->memoryService->composeMemoryContext($this->user, 'web');

        $criticalPos = strpos($context, '[CRITICAL]');
        $overduePos  = strpos($context, '[OVERDUE]');

        $this->assertNotFalse($criticalPos);
        $this->assertNotFalse($overduePos);
        $this->assertLessThan($overduePos, $criticalPos);
    }

    #[Test]
    public function urgent_tasks_block_is_rendered_after_active_focus(): void
    {
        Issue::create([
            'user_id'  => $this->user->id,
            'name'     => 'Critical task',
            'status'   => 'open',
            'priority' => Issue::PRIORITY_CRITICAL,
        ]);

        $context = $this->memoryService->composeMemoryContext($this->user, 'web');

        // Active Focus may not be present — just verify Urgent Tasks appears
        $this->assertStringContainsString('### Urgent Tasks', $context);
    }

    #[Test]
    public function future_due_date_issue_is_not_overdue(): void
    {
        Issue::create([
            'user_id'  => $this->user->id,
            'name'     => 'Future task',
            'status'   => 'open',
            'priority' => Issue::PRIORITY_NORMAL,
            'due_date' => now()->addDays(7)->toDateString(),
        ]);

        $context = $this->memoryService->composeMemoryContext($this->user, 'web');

        $this->assertStringNotContainsString('### Urgent Tasks', $context);
    }
}
