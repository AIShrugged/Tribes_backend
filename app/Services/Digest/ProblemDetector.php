<?php

namespace App\Services\Digest;

use App\Models\Issue;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Detects problem indicators deterministically from issues data.
 *
 * The output is passed to the LLM as facts (not prompts) — the LLM only rephrases.
 * This eliminates LLM hallucination of problems that don't exist and grounds the digest
 * in observable signal.
 */
class ProblemDetector
{
    /** Days without activity before an issue is considered "stuck". */
    private const STUCK_DAYS = 3;

    /**
     * Returns array of problem facts and array of win facts, plus a hasContent flag for
     * the anti-fatigue "skip empty digest" rule.
     *
     * @return array{problems: array, wins: array, has_content: bool}
     */
    public function detect(User $user, ?int $organizationId, array $period): array
    {
        $base = fn () => Issue::query()
            ->withoutTrashed()
            ->where('assignee_id', $user->id)
            ->when($organizationId, fn (Builder $q) => $q->where('organization_id', $organizationId));

        $stuck = (clone $base())
            ->whereNotIn('status', ['done', 'closed', 'cancelled'])
            ->where('updated_at', '<', Carbon::now()->subDays(self::STUCK_DAYS))
            ->orderBy('updated_at')
            ->limit(5)
            ->get();

        $overdue = (clone $base())
            ->whereNotIn('status', ['done', 'closed', 'cancelled'])
            ->whereNotNull('due_date')
            ->where('due_date', '<', Carbon::now()->toDateString())
            ->orderBy('due_date')
            ->limit(5)
            ->get();

        $doneInPeriod = (clone $base())
            ->where('status', 'done')
            ->whereBetween('close_date', [$period['from'], $period['to']])
            ->orderByDesc('close_date')
            ->limit(5)
            ->get();

        $problems = [];

        if ($stuck->isNotEmpty()) {
            $problems[] = [
                'type' => 'stuck',
                'severity' => 'M',
                'count' => $stuck->count(),
                'examples' => $stuck->pluck('name')->all(),
                'days_threshold' => self::STUCK_DAYS,
            ];
        }

        if ($overdue->isNotEmpty()) {
            $problems[] = [
                'type' => 'overdue',
                'severity' => 'H',
                'count' => $overdue->count(),
                'examples' => $overdue->map(fn ($i) => [
                    'name' => $i->name,
                    'due_date' => $i->due_date?->toDateString(),
                ])->all(),
            ];
        }

        $wins = [];

        if ($doneInPeriod->isNotEmpty()) {
            $wins[] = [
                'type' => 'completed',
                'count' => $doneInPeriod->count(),
                'examples' => $doneInPeriod->pluck('name')->all(),
            ];
        }

        return [
            'problems' => $problems,
            'wins' => $wins,
            'has_content' => ! empty($problems) || ! empty($wins),
        ];
    }
}
