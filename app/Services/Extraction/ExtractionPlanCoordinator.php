<?php

namespace App\Services\Extraction;

use App\Models\CalendarEvent;
use App\Models\ExtractionPlan;
use App\Models\TranscriptUpload;
use Illuminate\Support\Facades\DB;

/**
 * Fan-in barrier for the transcript moderation flow.
 *
 * The transcript fires two independent async producers (issues + decisions) that finish at
 * different times. The plan row is created EAGERLY by the gated TranscriptUploadController, so
 * producers only ever lockForUpdate()->first() it — there is no firstOrCreate race. Each producer
 * records its section; when all expected sections are ready the plan flips to pending_review.
 *
 * Per the post-transcript-pipeline rules, this service NEVER dispatches events: markSectionReady
 * returns the flip signal and the CALLER handles the upload-row update / empty-finalize after commit.
 */
class ExtractionPlanCoordinator
{
    /** Re-entrancy guard: only stage into a plan that is still collecting (post-approve regen must not re-stage). */
    public function hasCollectingPlan(CalendarEvent $event): bool
    {
        return $this->planQuery($event)
            ->where('status', ExtractionPlan::STATUS_COLLECTING)
            ->exists();
    }

    /**
     * Is the event under active moderation (a plan exists, awaiting approval)? Used by the review
     * deferral, which must hold the notification regardless of whether the plan has flipped from
     * collecting to pending_review yet — checking only 'collecting' would race the barrier flip and
     * leak a premature review notification.
     */
    public function hasActivePlan(CalendarEvent $event): bool
    {
        return $this->planQuery($event)
            ->whereIn('status', [
                ExtractionPlan::STATUS_COLLECTING,
                ExtractionPlan::STATUS_PENDING_REVIEW,
            ])
            ->exists();
    }

    /**
     * Does ANY plan exist for the event (any status)? This is the "is this a moderated manual upload"
     * signal for the section producers. They must gate on existence, NOT on 'collecting': if the
     * summary branch failed first and already marked the plan 'failed', a producer gating on
     * 'collecting' would fall through to the LIVE pipeline and persist/notify un-moderated issues.
     * With existence, a non-collecting plan still routes the producer into the (no-op) staged branch.
     */
    public function hasPlan(CalendarEvent $event): bool
    {
        return $this->planQuery($event)->exists();
    }

    /**
     * Record a section's payload. Returns the new plan status if this call flips it
     * (pending_review, or approved when there is zero content to review), else null.
     */
    public function markSectionReady(CalendarEvent $event, string $section, array $payload): ?string
    {
        return DB::transaction(function () use ($event, $section, $payload) {
            $plan = $this->lockPlan($event);

            if (! $plan || ! $plan->isCollecting()) {
                return null; // no eager row, or already flipped/failed — idempotent no-op
            }

            $planData = $plan->plan ?? [];
            $planData[$section] = $payload;
            $plan->plan = $planData;

            $sectionStatus = $plan->section_status ?? [];
            $sectionStatus[$section] = 'ready';
            $plan->section_status = $sectionStatus;

            $expected = $plan->expected_sections ?? [];
            $allReady = ! empty($expected)
                && collect($expected)->every(fn ($s) => ($sectionStatus[$s] ?? null) === 'ready');

            $flip = null;
            if ($allReady) {
                $hasContent = ! empty($planData['issues']['items'] ?? [])
                    || ! empty($planData['decisions']['items'] ?? []);

                // 0 issues + 0 decisions: nothing for a human to review — finalize empty (the caller
                // replays the downstream so the upload still reaches 'done', as the flag-off skip does).
                $plan->status = $hasContent
                    ? ExtractionPlan::STATUS_PENDING_REVIEW
                    : ExtractionPlan::STATUS_APPROVED;
                $flip = $plan->status;
            }

            $plan->save();

            return $flip;
        });
    }

    /** Mark a section (and the whole plan) failed so the barrier can never strand in 'collecting'. */
    public function markSectionFailed(CalendarEvent $event, string $section): void
    {
        DB::transaction(function () use ($event, $section) {
            $plan = $this->lockPlan($event);

            if (! $plan || ! $plan->isCollecting()) {
                return;
            }

            $sectionStatus = $plan->section_status ?? [];
            $sectionStatus[$section] = 'failed';
            $plan->section_status = $sectionStatus;
            $plan->status = ExtractionPlan::STATUS_FAILED;
            $plan->save();

            $this->failTranscriptUpload($event, 'Extraction failed during moderation');
        });
    }

    /**
     * Handle the flip signal from markSectionReady AFTER its transaction commits. Called by the
     * producer (orchestrator) so dispatch ordering stays in the job/listener layer:
     *  - pending_review → surface the review state on the upload row;
     *  - approved (0 issues + 0 decisions) → finalize empty so the upload still reaches 'done'.
     */
    public function finalizeFlip(CalendarEvent $event, ?string $flip): void
    {
        if ($flip === ExtractionPlan::STATUS_PENDING_REVIEW) {
            $this->markTranscriptUploadPendingReview($event);

            return;
        }

        if ($flip === ExtractionPlan::STATUS_APPROVED) {
            $plan = $this->planQuery($event)->first();
            if ($plan) {
                app(\App\Services\ExtractionPlan\ApproveExtractionPlanService::class)->finalizeAutoApproved($plan);
            }
        }
    }

    /** Move the transcript upload-log row to pending_review (the UI keys on the upload row's status). */
    public function markTranscriptUploadPendingReview(CalendarEvent $event): void
    {
        TranscriptUpload::where('calendar_event_id', $event->id)
            ->whereNotIn('status', ['done', 'failed'])
            ->update(['status' => 'pending_review']);
    }

    private function failTranscriptUpload(CalendarEvent $event, string $message): void
    {
        TranscriptUpload::where('calendar_event_id', $event->id)
            ->whereNotIn('status', ['done', 'failed'])
            ->update(['status' => 'failed', 'error_message' => $message]);
    }

    private function planQuery(CalendarEvent $event)
    {
        return ExtractionPlan::where('sourceable_type', CalendarEvent::class)
            ->where('sourceable_id', $event->id);
    }

    private function lockPlan(CalendarEvent $event): ?ExtractionPlan
    {
        return $this->planQuery($event)->lockForUpdate()->first();
    }
}
