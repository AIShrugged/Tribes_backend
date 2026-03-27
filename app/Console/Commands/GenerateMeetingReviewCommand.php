<?php

namespace App\Console\Commands;

use App\Models\CalendarEvent;
use App\Services\Meeting\MeetingReviewService;
use Illuminate\Console\Command;

class GenerateMeetingReviewCommand extends Command
{
    protected $signature = 'meeting:review {event_id : Calendar Event ID}';

    protected $description = 'Generate a meeting effectiveness review for a calendar event';

    public function handle(MeetingReviewService $service): int
    {
        $event = CalendarEvent::findOrFail($this->argument('event_id'));

        $transcriptCount = $event->transcriptEntries()->count();

        if ($transcriptCount === 0) {
            $this->error("Event #{$event->id} has no transcript entries.");
            return self::FAILURE;
        }

        $this->info("Generating review for event #{$event->id}: {$event->title}");
        $this->info("Transcript entries: {$transcriptCount}");
        $this->info('Processing...');

        $review = $service->generate($event);

        if ($review->status === 'failed') {
            $this->error('Review generation failed. Check logs for details.');
            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Score: {$review->score}/10");
        $this->newLine();

        if ($review->score_breakdown) {
            $this->table(
                ['Criterion', 'Score'],
                collect($review->score_breakdown)->map(fn ($v, $k) => [$k, $v])->values()->toArray()
            );
        }

        $this->newLine();
        $this->info('Key Insight:');
        $this->line($review->key_insight ?? '(empty)');

        if ($review->suggestions) {
            $this->newLine();
            $this->info('Suggestions:');
            foreach ($review->suggestions as $i => $suggestion) {
                $this->line(($i + 1) . ". {$suggestion}");
            }
        }

        if ($review->agenda_analysis) {
            $this->newLine();
            $this->info('Agenda Analysis:');
            $this->line(json_encode($review->agenda_analysis, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }

        if ($review->trend) {
            $this->newLine();
            $this->info('Trend (vs previous meetings):');
            $this->line($review->trend);
        }

        if ($review->previous_suggestions_check) {
            $this->newLine();
            $this->info('Previous Suggestions Check:');
            foreach ($review->previous_suggestions_check as $check) {
                $status = strtoupper($check['status'] ?? '?');
                $suggestion = $check['suggestion'] ?? '';
                $this->line("  [{$status}] {$suggestion}");
                if (!empty($check['comment'])) {
                    $comment = $check['comment'];
                    $this->line("    → {$comment}");
                }
            }
        }

        $this->newLine();
        $this->info("Review saved with ID: {$review->id}");

        return self::SUCCESS;
    }
}
