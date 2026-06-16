<?php

namespace App\Jobs;

use App\Models\CalendarEvent;
use App\Models\Team;
use App\Models\User;
use App\Services\Extraction\ExtractionPlanCoordinator;
use App\Services\Extraction\ExtractionPlanSections;
use App\Services\IssueExtractionService;
use App\Services\Issue\IssueExtractionFanout;
use App\Services\IssueMergeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExtractIssuesFromTranscriptJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public CalendarEvent $calendarEvent,
        public Team $team,
        public User $user,
    ) {
        $this->onQueue('heavy');
    }

    public function handle(IssueExtractionService $service): void
    {
        if ($this->isGated()) {
            $this->stageIssuesPlan($service);

            return;
        }

        $issues = $service->extract($this->calendarEvent, $this->team, $this->user);

        app(IssueExtractionFanout::class)->afterIssues($issues, $this->calendarEvent, $this->team, $this->user);
    }

    /**
     * Gated path: compute (Pass-1 + merge dedup) WITHOUT persisting, stage the issues section, and
     * let the barrier flip when decisions are also ready. A Pass-1 LLM failure propagates so the
     * queue can fail the job (and failed() marks the section failed) — never a false auto-approve.
     */
    private function stageIssuesPlan(IssueExtractionService $service): void
    {
        $items = $service->computeItems($this->calendarEvent, $this->team); // ?array; throws on LLM error

        $computed = $items === null
            ? ['items' => [], 'decisions' => null, 'existing_snapshots' => []]
            : app(IssueMergeService::class)->computePlan($items, $this->calendarEvent, $this->team);

        $section = ExtractionPlanSections::issues($computed);
        $section['team_id'] = $this->team->id;
        $section['user_id'] = $this->user->id;

        $coordinator = app(ExtractionPlanCoordinator::class);
        $flip = $coordinator->markSectionReady($this->calendarEvent, 'issues', $section);
        $coordinator->finalizeFlip($this->calendarEvent, $flip);
    }

    /** Anti-strand: a crashed gated compute must fail the plan, not leave the barrier collecting forever. */
    public function failed(\Throwable $e): void
    {
        if ($this->isGated()) {
            app(ExtractionPlanCoordinator::class)->markSectionFailed($this->calendarEvent, 'issues');
        }
    }

    /**
     * Moderated iff a plan exists for the event — created by TranscriptUploadController for ANY
     * manual upload (new OR attach-to-existing). Platform-agnostic; Recall has no plan.
     *
     * Gate on EXISTENCE (any status), not 'collecting': if the summary branch failed first and marked
     * the plan 'failed', gating on 'collecting' would fall through to the live pipeline and persist
     * issues un-moderated. With existence, a failed plan still routes here → markSectionReady no-ops →
     * issues dropped (the upload is failed; the user re-uploads). No moderation bypass.
     */
    private function isGated(): bool
    {
        return app(ExtractionPlanCoordinator::class)->hasPlan($this->calendarEvent);
    }
}
