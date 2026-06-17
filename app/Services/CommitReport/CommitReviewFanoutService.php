<?php

namespace App\Services\CommitReport;

use App\Models\AgentProfile;
use App\Models\AgentTask;
use App\Models\AgentTaskRun;
use App\Models\CommitReport;
use App\Models\CommitReportItem;
use App\Services\AgentTaskSchedulerService;
use Illuminate\Support\Facades\Log;

/**
 * Pass-2 fan-out: after a daily commit-report run (Pass 1) completes, dispatch ONE per-commit
 * review sub-run for each matched-pending item. Backend-driven (not the agent). A dead sub-run
 * never affects the day report or its siblings.
 */
class CommitReviewFanoutService
{
    public function __construct(
        private readonly AgentTaskSchedulerService $scheduler,
    ) {}

    public function maybeDispatch(AgentTask $task, AgentTaskRun $run): void
    {
        if (($task->metadata['kind'] ?? null) !== 'commit_report') {
            return; // only the daily commit-report task fans out
        }
        if (config('queue.default') === 'sync' && (bool) config('agent.commit_report.review.skip_under_sync', true)) {
            return; // local sync queue would re-enter inline sub-runs synchronously
        }

        $report = CommitReport::query()
            ->where('generated_by_agent_task_run_id', $run->id)
            ->latest('id')
            ->first();
        if (! $report) {
            return;
        }

        $profile = AgentProfile::query()->where('key', 'commit-reviewer')->first();
        if (! $profile) {
            Log::warning('commit-reviewer profile missing; skipping commit-review fan-out', ['agent_task_run_id' => $run->id]);

            return;
        }

        $cap = max(0, (int) config('agent.commit_report.review.max_subruns_per_report', 30));

        $pending = CommitReportItem::query()
            ->where('commit_report_id', $report->id)
            ->where('review_status', 'pending')
            ->whereNotNull('matched_issue_id')
            ->orderBy('position')
            ->get();

        $dispatched = 0;
        foreach ($pending as $item) {
            if ($dispatched >= $cap) {
                $item->update(['review_status' => 'deferred']); // over cap: distinguishable from in-flight
                continue;
            }

            try {
                // Dispatch FIRST, flip to in_progress only on success. A transient dispatch failure
                // (broker/DB blip, lock contention) then leaves THIS item 'pending' (re-dispatchable)
                // and never aborts the fan-out for the remaining items.
                $subTask = $this->buildSubTask($task, $report, $item, $profile, $run->id);
                $this->scheduler->dispatchTaskNow($subTask, true);
                $item->update(['review_status' => 'in_progress']);
                $dispatched++;
            } catch (\Throwable $e) {
                Log::warning('commit-review sub-dispatch failed; item left pending', [
                    'commit_report_item_id' => $item->id,
                    'sha' => $item->sha,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($dispatched > 0) {
            Log::info('Commit-review fan-out dispatched', ['commit_report_id' => $report->id, 'sub_runs' => $dispatched]);
        }
    }

    private function buildSubTask(AgentTask $parent, CommitReport $report, CommitReportItem $item, AgentProfile $profile, int $originRunId): AgentTask
    {
        return AgentTask::create([
            'user_id' => $parent->user_id,
            'organization_id' => $parent->organization_id,
            'team_id' => $parent->team_id,
            'agent_profile_id' => $profile->id,
            'parent_agent_task_id' => $parent->id,
            'origin_agent_task_run_id' => $originRunId,
            'name' => "Commit review {$report->repo}@{$report->branch} ".substr($item->sha, 0, 7),
            'prompt' => 'Review this single commit against its matched task and record the architect assessment. Use commit_report_id, repo, branch, sha and matched_issue_id from the Task Payload below. Call update_commit_report_item EXACTLY ONCE, then stop.',
            'schedule_type' => 'one_off',
            'execution_mode' => 'inline',
            'agent_task_type' => 'background',
            'output_mode' => 'plain',
            'enabled' => true,
            'next_run_at' => now(),
            'max_attempts' => max(1, (int) config('agent.commit_report.review.sub_run_max_attempts', 2)),
            'allowed_tools' => ['github_get_commit', 'get_issue_detail', 'update_commit_report_item'],
            'allowed_outbound_hosts' => ['api.github.com'],
            'input_payload' => [
                'commit_report_id' => $report->id,
                'repo' => $report->repo,
                'branch' => $report->branch,
                'sha' => $item->sha,
                'matched_issue_id' => $item->matched_issue_id,
            ],
            'metadata' => ['kind' => 'commit_review', 'origin_run_id' => $originRunId],
        ]);
    }
}
