<?php

namespace App\Console\Commands;

use App\Models\CalendarEvent;
use App\Services\Meeting\DetectRepeatedDiscussionsService;
use Illuminate\Console\Command;

class DetectRepeatedDiscussionsCommand extends Command
{
    protected $signature = 'meeting:detect-repeats
                            {event_id : Calendar Event ID}
                            {--dry-run : Show what would be sent to LLM without calling it}';

    protected $description = 'Detect repeated discussions for a meeting summary';

    public function handle(DetectRepeatedDiscussionsService $service): int
    {
        $event = CalendarEvent::with(['meetingSummary', 'source.user.teams'])->findOrFail($this->argument('event_id'));

        $summary = $event->meetingSummary;

        if (! $summary) {
            $this->error("Event #{$event->id} has no meeting summary.");

            return self::FAILURE;
        }

        $decisions = array_filter((array) ($summary->decisions ?? []));

        if (empty($decisions)) {
            $this->error('Summary has no decisions to analyze.');

            return self::FAILURE;
        }

        $teams = $event->source?->user?->teams;

        if (! $teams || $teams->isEmpty()) {
            $this->error("No teams found for this event's user.");

            return self::FAILURE;
        }

        $this->info("Event: #{$event->id} — {$event->title}");
        $this->info('Decisions in this meeting: '.count($decisions));
        $this->newLine();

        foreach ($teams as $team) {
            $preview = $service->preview($summary, $team);
            $estimatedTokens = (int) round($preview['estimated_chars'] / 4);

            $this->line("Team: <comment>{$team->name}</comment>");
            $this->line("  New decisions:        {$preview['new_decisions']}");
            $this->line("  Historical decisions: {$preview['historical_decisions']}");
            $this->line("  Payload size:         ~{$preview['estimated_chars']} chars (~{$estimatedTokens} tokens input)");
            $this->newLine();
        }

        if ($this->option('dry-run')) {
            $this->warn('--dry-run: LLM was NOT called. Remove the flag to run for real.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Proceed and call the LLM?', true)) {
            return self::SUCCESS;
        }

        $this->newLine();
        $allMatches = [];

        foreach ($teams as $team) {
            $this->info("Processing team: {$team->name}...");
            $matches = $service->detect($summary, $team);

            if (! empty($matches)) {
                $allMatches = array_merge($allMatches, $matches);
            }
        }

        $summary->update(['repeated_discussions' => $allMatches]);

        $this->newLine();

        if (empty($allMatches)) {
            $this->info('No repeated discussions found.');

            return self::SUCCESS;
        }

        $this->info('Repeated discussions found: '.count($allMatches));
        $this->newLine();

        foreach ($allMatches as $i => $match) {
            $this->line(($i + 1).". New: «{$match['new_decision']}»");
            $this->line("   Previous ({$match['previous_date']}): «{$match['previous_decision']}»");
            if ($match['previous_meeting_title']) {
                $this->line("   Meeting: {$match['previous_meeting_title']}");
            }
            $this->newLine();
        }

        $this->info("Saved to meeting_summaries.repeated_discussions (summary ID: {$summary->id})");

        return self::SUCCESS;
    }
}
