<?php

namespace App\Services\Agent\Support;

/**
 * Tiny per-run call counter. An agent run is one PHP process, so a static map keyed by the
 * agent task run id (or 0 for interactive/no-run) is enough to cap how many times a heavy
 * tool may be called within a single run.
 */
class AgentRunToolBudget
{
    /** @var array<string,int> */
    private static array $counts = [];

    /** True if a call is allowed (and records it); false once the budget for (run, tool) is spent. */
    public static function consume(?int $runId, string $tool, int $max): bool
    {
        $key = ($runId ?? 0).':'.$tool;
        $current = self::$counts[$key] ?? 0;
        if ($current >= $max) {
            return false;
        }
        self::$counts[$key] = $current + 1;

        return true;
    }

    /** Test/maintenance helper: reset all counters (or just one run's). */
    public static function reset(?int $runId = null): void
    {
        if ($runId === null) {
            self::$counts = [];

            return;
        }
        foreach (array_keys(self::$counts) as $key) {
            if (str_starts_with($key, $runId.':')) {
                unset(self::$counts[$key]);
            }
        }
    }
}
