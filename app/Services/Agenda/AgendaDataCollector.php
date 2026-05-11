<?php

namespace App\Services\Agenda;

use Carbon\Carbon;
use App\Models\CalendarEvent;
use App\Models\MeetingSummary;
use Illuminate\Support\Collection;

class AgendaDataCollector
{
    public function collectStructuredData(
        CalendarEvent $event,
        ?CalendarEvent $previousEvent,
        ?MeetingSummary $previousSummary,
        Collection $issues,
        Collection $seriesEventIds,
    ): array {
        $event->loadMissing('profiles.user');
        $attendees      = $event->profiles->map(fn ($p) => $p->user?->name)->filter()->values()->toArray();
        $attendeeEmails = $event->profiles->map(fn ($p) => $p->user?->email)->filter()->values()->toArray();

        $previousSummaryData = null;
        if ($previousSummary && $previousEvent) {
            $daysSince = (int) Carbon::parse($previousEvent->starts_at)->diffInDays(Carbon::parse($event->starts_at));
            $previousSummaryData = [
                'days_ago'   => $daysSince,
                'summary'    => $previousSummary->summary,
                'key_points' => $previousSummary->key_points ?? [],
                'decisions'  => $previousSummary->decisions ?? [],
            ];
        }

        $completedTasks     = collect();
        $openTasks          = collect();
        $overdueTasks       = collect();
        $unresolvedDecisions = collect();

        if ($seriesEventIds->isNotEmpty()) {
            $now    = Carbon::now();
            $mapTask = fn ($i) => [
                'name'     => $i->name,
                'assignee' => $i->assignee?->name ?? $i->assignee_name,
                'due_date' => $i->due_date ? Carbon::parse($i->due_date)->format('d.m.Y') : null,
            ];

            if ($previousEvent) {
                $completedTasks = $issues
                    ->where('status', 'done')
                    ->filter(fn ($i) => Carbon::parse($i->updated_at)->gte(Carbon::parse($previousEvent->starts_at)))
                    ->map($mapTask)
                    ->values();
            }

            $openIssues   = $issues->filter(fn ($i) => !in_array($i->status, ['done', 'cancelled']));
            $overdueTasks = $openIssues
                ->filter(fn ($i) => $i->due_date && Carbon::parse($i->due_date)->lt($now))
                ->map($mapTask)->values();
            $openTasks    = $openIssues
                ->filter(fn ($i) => !$i->due_date || Carbon::parse($i->due_date)->gte($now))
                ->map($mapTask)->values();

            if ($previousSummary && $previousEvent && !empty($previousSummary->decisions)) {
                $prevEventIssues = $issues->where('sourceable_id', $previousEvent->id);
                $activeTasks     = $prevEventIssues->filter(fn ($i) => !in_array($i->status, ['done', 'cancelled']))->count();
                $totalTasks      = $prevEventIssues->count();

                if (!($totalTasks > 0 && $activeTasks === 0)) {
                    $unresolvedDecisions = collect($previousSummary->decisions);
                }
            }
        }

        return [
            'attendees'            => $attendees,
            'attendee_emails'      => $attendeeEmails,
            'previous_summary'     => $previousSummaryData,
            'completed_tasks'      => $completedTasks->toArray(),
            'open_tasks'           => $openTasks->toArray(),
            'overdue_tasks'        => $overdueTasks->toArray(),
            'unresolved_decisions' => $unresolvedDecisions->toArray(),
        ];
    }

    public function getTasksBetweenMeetings(
        CalendarEvent $event,
        ?CalendarEvent $previousEvent,
        Collection $issues,
    ): array {
        if (!$previousEvent) {
            return [];
        }

        return $issues
            ->filter(fn ($i) => $i->created_at >= $previousEvent->starts_at
                && $i->created_at < $event->starts_at)
            ->map(fn ($i) => [
                'name'     => $i->name,
                'assignee' => $i->assignee?->name ?? $i->assignee_name,
                'status'   => match ($i->status) {
                    'done'        => 'готово',
                    'in_progress' => 'в работе',
                    'cancelled'   => 'отменено',
                    default       => 'открыта',
                },
            ])
            ->values()
            ->toArray();
    }

    public function getBacklogStats(Collection $issues, ?CalendarEvent $previousEvent): array
    {
        $open       = $issues->where('status', 'open')->count();
        $inProgress = $issues->where('status', 'in_progress')->count();
        $done       = $issues->where('status', 'done')->count();
        $cancelled  = $issues->where('status', 'cancelled')->count();
        $paused     = $issues->whereNotIn('status', ['open', 'in_progress', 'done', 'cancelled'])->count();
        $total      = $issues->count();

        $deltaOpen       = 0;
        $deltaInProgress = 0;
        $deltaDone       = 0;

        if ($previousEvent) {
            $since           = $previousEvent->starts_at;
            $deltaOpen       = $issues->where('status', 'open')->filter(fn ($i) => $i->created_at >= $since)->count();
            $deltaInProgress = $issues->where('status', 'in_progress')->filter(fn ($i) => $i->updated_at >= $since)->count();
            $deltaDone       = $issues->where('status', 'done')->filter(fn ($i) => $i->updated_at >= $since)->count();
        }

        return [
            'total'            => $total,
            'open'             => $open,
            'in_progress'      => $inProgress,
            'done'             => $done,
            'cancelled'        => $cancelled,
            'paused'           => $paused,
            'delta_open'       => $deltaOpen,
            'delta_in_progress' => $deltaInProgress,
            'delta_done'       => $deltaDone,
        ];
    }

    public function extractTopicsFromSummary(?string $summary): array
    {
        if (!$summary) {
            return [];
        }

        $topics = [];

        if (preg_match_all('/### (?:Тема \d+[:.]\s*)?(.+?)(?=\n###|\n===|\z)/s', $summary, $matches)) {
            foreach ($matches[1] as $topic) {
                $title = trim(explode("\n", trim($topic))[0]);
                if (!str_contains($title, 'Следующие шаги') && $title !== '') {
                    $topics[] = $title;
                }
            }
        }

        if (empty($topics) && preg_match('/\*{0,2}Краткое содержание\*{0,2}\n(.*?)(?:\n\n|\n\*\*|\n\||\z)/s', $summary, $m)) {
            foreach (explode("\n", trim($m[1])) as $line) {
                $line = trim(ltrim(trim($line), '-'));
                if ($line !== '') {
                    $colonPos = mb_strpos($line, ':');
                    $topics[] = $colonPos && $colonPos < 80
                        ? mb_substr($line, 0, $colonPos)
                        : mb_substr($line, 0, 80);
                }
            }
        }

        return array_slice($topics, 0, 6);
    }
}
