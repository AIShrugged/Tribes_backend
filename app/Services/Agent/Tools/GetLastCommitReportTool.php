<?php

namespace App\Services\Agent\Tools;

use App\Services\CommitReport\CommitReportService;
use Illuminate\Support\Carbon;

class GetLastCommitReportTool extends AbstractAgentTool
{
    private const TZ = 'Europe/Moscow';

    public function __construct(
        private readonly CommitReportService $service,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'get_last_commit_report';
    }

    public function getDescription(): string
    {
        return 'Return the watermark for a repo+branch AND the ready-to-use scan window. Use the window VERBATIM: pass window.since/window.until to github_list_commits, and window.period_start/window.period_end to save_commit_report. Do NOT compute or shift dates yourself. last_period_end is null when no prior report exists.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'repo' => ['type' => 'string', 'description' => 'Repository as owner/name, e.g. AIShrugged/Tribes_backend.'],
                'branch' => ['type' => 'string', 'description' => 'Branch name, e.g. dev.'],
            ],
            'required' => ['repo', 'branch'],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $repo = trim((string) ($parameters['repo'] ?? ''));
        $branch = trim((string) ($parameters['branch'] ?? ''));

        if ($repo === '' || $branch === '') {
            return ['success' => false, 'error' => 'repo and branch are required'];
        }

        $lastPeriodEnd = $this->service->latestPeriodEnd($repo, $branch);

        return [
            'success' => true,
            'repo' => $repo,
            'branch' => $branch,
            'last_period_end' => $lastPeriodEnd?->toDateString(),
            'last_status' => $this->service->latestStatus($repo, $branch),
            'window' => $this->computeWindow($lastPeriodEnd),
        ];
    }

    /**
     * Compute the scan window SERVER-SIDE so the model never does date arithmetic.
     * Window is [since, until) in UTC; period_[start|end] are inclusive calendar dates
     * (Y-m-d). until = today 00:00 MSK; period_end = yesterday (MSK). since/period_start =
     * the day after the watermark (or yesterday on a first run), clamped so they never
     * exceed period_end (already-covered => a single yesterday-only window, idempotent).
     */
    private function computeWindow(?Carbon $lastPeriodEnd): array
    {
        $todayMsk = Carbon::now(self::TZ)->startOfDay();   // today 00:00 MSK
        $yesterdayMsk = $todayMsk->copy()->subDay();       // yesterday 00:00 MSK
        $periodEnd = $yesterdayMsk->copy();                // inclusive last covered day

        if ($lastPeriodEnd === null) {
            $startMsk = $yesterdayMsk->copy();
        } else {
            // Parse the watermark DATE fresh in MSK (avoid any stored-tz drift), next day.
            $startMsk = Carbon::parse($lastPeriodEnd->toDateString(), self::TZ)->addDay();
            if ($startMsk->greaterThan($periodEnd)) {
                $startMsk = $yesterdayMsk->copy(); // already covered through yesterday
            }
        }

        return [
            'since' => $startMsk->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'until' => $todayMsk->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'period_start' => $startMsk->toDateString(),
            'period_end' => $periodEnd->toDateString(),
        ];
    }
}
