<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the config-driven kill-switches for the autonomous LLM scheduler
 * (config/features.php: enable_ai_scheduler, enable_agent_tasks_dispatch).
 *
 * The LLM-generating scheduled commands must be gated by ->when(); the non-AI
 * maintenance / delivery commands must keep running regardless.
 */
class AiSchedulerKillSwitchTest extends TestCase
{
    /** Scheduled commands that call OpenRouter — gated by enable_ai_scheduler. */
    private const AI_COMMANDS = [
        'today:generate-nudges',
        'tasks:generate-daily-digests',
        'tasks:generate-meetings-advice',
        'tasks:send-weekly-digests',
        'agenda:generate',
        'issues:generate-health-reports',
        'tasks:process-telegram',
        'insight:process-telegram',
    ];

    /** Non-AI maintenance / delivery — must never be gated by the AI switch. */
    private const NON_AI_COMMANDS = [
        'queue:monitor',
        'openrouter:check-balance',
        'cpm:process-pending',
        'agenda:send',
        'meetings:send-morning-brief',
        'notify:stuck-tasks',
    ];

    /**
     * @return array<string, bool> summary => filtersPass
     */
    private function scheduleFilters(): array
    {
        // Force routes/console.php to load so the Schedule singleton is populated.
        $this->app->make(ConsoleKernel::class)->bootstrap();

        $rows = [];
        foreach ($this->app->make(Schedule::class)->events() as $event) {
            /** @var Event $event */
            $rows[$event->getSummaryForDisplay()] = $event->filtersPass($this->app);
        }

        return $rows;
    }

    private function passes(array $rows, string $needle): ?bool
    {
        foreach ($rows as $summary => $pass) {
            if (str_contains($summary, $needle)) {
                return $pass;
            }
        }

        return null; // command not found in the schedule
    }

    #[Test]
    public function ai_commands_are_gated_off_when_the_switch_is_disabled(): void
    {
        config(['features.enable_ai_scheduler' => false]);
        config(['features.enable_agent_tasks_dispatch' => false]);

        $rows = $this->scheduleFilters();

        foreach (self::AI_COMMANDS as $cmd) {
            $this->assertFalse(
                $this->passes($rows, $cmd),
                "Expected AI command [$cmd] to be gated off (filtersPass=false)."
            );
        }

        $this->assertFalse(
            $this->passes($rows, 'agent-tasks:dispatch'),
            'Expected agent-tasks:dispatch to be gated off by its own switch.'
        );
    }

    #[Test]
    public function ai_commands_run_when_the_switch_is_enabled(): void
    {
        config(['features.enable_ai_scheduler' => true]);
        config(['features.enable_agent_tasks_dispatch' => true]);

        $rows = $this->scheduleFilters();

        foreach (self::AI_COMMANDS as $cmd) {
            $this->assertTrue(
                $this->passes($rows, $cmd),
                "Expected AI command [$cmd] to run when the switch is enabled."
            );
        }

        $this->assertTrue(
            $this->passes($rows, 'agent-tasks:dispatch'),
            'Expected agent-tasks:dispatch to run when its switch is enabled.'
        );
    }

    #[Test]
    public function non_ai_commands_are_never_gated_by_the_ai_switch(): void
    {
        config(['features.enable_ai_scheduler' => false]);
        config(['features.enable_agent_tasks_dispatch' => false]);

        $rows = $this->scheduleFilters();

        foreach (self::NON_AI_COMMANDS as $cmd) {
            $this->assertTrue(
                $this->passes($rows, $cmd),
                "Non-AI command [$cmd] must keep running even with the AI switch off."
            );
        }
    }

    #[Test]
    public function the_agent_tasks_switch_is_independent_of_the_ai_switch(): void
    {
        // AI switch on, agent-tasks switch off: only agent-tasks should be gated.
        config(['features.enable_ai_scheduler' => true]);
        config(['features.enable_agent_tasks_dispatch' => false]);

        $rows = $this->scheduleFilters();

        $this->assertFalse(
            $this->passes($rows, 'agent-tasks:dispatch'),
            'agent-tasks:dispatch should be gated by its own switch.'
        );
        $this->assertTrue(
            $this->passes($rows, 'today:generate-nudges'),
            'AI commands should still run when only the agent-tasks switch is off.'
        );
    }
}
