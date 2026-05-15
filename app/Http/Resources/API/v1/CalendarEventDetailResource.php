<?php

namespace App\Http\Resources\API\v1;

use App\Models\AgendaTemplate;
use App\Models\CalendarEvent;
use App\Models\Decision;
use App\Models\UpcomingAgenda;
use App\Services\Agenda\AgendaRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class CalendarEventDetailResource extends JsonResource
{
    public function __construct(mixed $resource, private readonly ?CalendarEvent $previousMeeting = null)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        /** @var CalendarEvent $event */
        $event = $this->resource;

        $template = $this->resolveTemplate($event);
        $renderer = app(AgendaRenderer::class);

        return [
            'event' => array_merge(
                CalendarEventResource::make($event)->resolve($request),
                [
                    'meeting_link' => $event->url ? [
                        'label' => 'Join meeting',
                        'url' => $event->url,
                    ] : null,
                ],
            ),
            'is_past' => (bool) $event->ends_at?->isPast(),
            'participants' => ParticipantResource::collection($event->participants)->resolve($request),
            'agendas' => $this->buildAgendas($event, $renderer, $template),
            'tasks' => MeetingTaskResource::collection($event->tasks)->resolve($request),
            'summary' => $event->meetingSummary
                ? MeetingSummaryResource::make($event->meetingSummary)->resolve($request)
                : null,
            'review' => $event->meetingReview
                ? MeetingReviewResource::make($event->meetingReview)->resolve($request)
                : null,
            'decisions' => $this->buildDecisions($event),
            'previous_meeting' => $this->previousMeeting ? [
                'id' => $this->previousMeeting->id,
                'title' => $this->previousMeeting->title,
                'starts_at' => $this->previousMeeting->starts_at,
                'ends_at' => $this->previousMeeting->ends_at,
                'url' => $this->previousMeeting->url,
                'summary' => $this->previousMeeting->meetingSummary?->summary,
                'summary_excerpt' => Str::limit((string) $this->previousMeeting->meetingSummary?->summary, 220),
            ] : null,
            'key_takeaways' => $this->buildKeyTakeaways($event, $request),
        ];
    }

    /**
     * Build agenda list: MeetingAgenda rows for this event (general + personal-for-user)
     * plus an UpcomingAgenda fallback ("next-meeting prep" generated from an earlier event
     * in the same series) when the meeting is still upcoming and the user has no personal
     * agenda yet. Mirrors {@see \App\Services\Today\TodayBriefingService::loadAgendaContent}.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildAgendas(CalendarEvent $event, AgendaRenderer $renderer, ?AgendaTemplate $template): array
    {
        $agendas = $event->agendas
            ->map(fn ($agenda) => [
                'id' => $agenda->id,
                'type' => $agenda->type,
                'status' => $agenda->status,
                'content' => $agenda->isGeneral() && ! empty($agenda->raw_json)
                    ? $renderer->renderForWeb($agenda->raw_json, $event, $template)
                    : $agenda->content,
                'user_id' => $agenda->user_id,
                'sent_at' => $agenda->sent_at,
                'send_scheduled_at' => $agenda->send_scheduled_at,
            ])
            ->values()
            ->all();

        // Only surface upcoming-agenda fallback for not-yet-completed meetings.
        // Past meetings with a summary already have their own derived artifacts.
        if ($event->ends_at?->isPast() || $event->meetingSummary) {
            return $agendas;
        }

        $userId = Auth::id();
        if (! $userId) {
            return $agendas;
        }

        $hasPersonalForUser = collect($agendas)->contains(
            fn (array $a) => ($a['user_id'] ?? null) === $userId
        );
        if ($hasPersonalForUser) {
            return $agendas;
        }

        $upcoming = UpcomingAgenda::query()
            ->where('user_id', $userId)
            ->where('series_key', $event->seriesKey())
            ->where('status', 'done')
            ->orderByDesc('updated_at')
            ->first();

        if (! $upcoming || ! $upcoming->content) {
            return $agendas;
        }

        $agendas[] = [
            'id' => "upcoming-{$upcoming->id}",
            'type' => 'upcoming',
            'status' => $upcoming->status,
            'content' => $upcoming->content,
            'user_id' => $upcoming->user_id,
            'sent_at' => null,
            'send_scheduled_at' => null,
        ];

        return $agendas;
    }

    /**
     * Decisions из таблицы (а не из summary JSON) — со связанными задачами и флагом покрытия.
     * Обходит коллизию имени `MeetingSummary::decisions` (JSON cast vs hasMany relation) явным запросом.
     */
    private function buildDecisions(CalendarEvent $event): array
    {
        return Decision::query()
            ->where('calendar_event_id', $event->id)
            ->with(['issues:id,name,status', 'authorUser:id,name'])
            ->orderBy('id')
            ->get()
            ->map(fn (Decision $decision) => [
                'id'              => $decision->id,
                'text'            => $decision->text,
                'topic'           => $decision->topic,
                'author_raw_name' => $decision->author_raw_name,
                'author'          => $decision->authorUser ? [
                    'id'   => $decision->authorUser->id,
                    'name' => $decision->authorUser->name,
                ] : null,
                'linked_issues'   => $decision->issues->map(fn ($issue) => [
                    'id'     => $issue->id,
                    'name'   => $issue->name,
                    'status' => $issue->status,
                ])->values()->all(),
                'is_uncovered'    => $decision->issues->isEmpty(),
            ])
            ->values()
            ->all();
    }

    private function buildKeyTakeaways(CalendarEvent $event, Request $request): array
    {
        $items = [];

        $summary = $event->meetingSummary;
        if ($summary) {
            if ($summary->summary) {
                $items[] = $this->takeaway(
                    id: "summary-overview-{$summary->id}",
                    kind: 'overview',
                    label: 'Overview',
                    title: (string) $summary->summary,
                    excerpt: $this->excerpt((string) $summary->summary),
                    fullText: (string) $summary->summary,
                    source: 'meeting_summary',
                    anchor: 'summary.overview',
                );
            }

            foreach (array_values($summary->decisions ?? []) as $index => $decision) {
                $items[] = $this->takeaway(
                    id: "summary-decision-{$summary->id}-{$index}",
                    kind: 'decision',
                    label: 'Decision',
                    title: (string) $decision,
                    excerpt: $this->excerpt((string) $decision),
                    fullText: (string) $decision,
                    source: 'meeting_summary',
                    anchor: "summary.decisions.{$index}",
                );
            }

            foreach (array_values($summary->key_points ?? []) as $index => $point) {
                $items[] = $this->takeaway(
                    id: "summary-key-point-{$summary->id}-{$index}",
                    kind: 'info',
                    label: 'Info',
                    title: (string) $point,
                    excerpt: $this->excerpt((string) $point),
                    fullText: (string) $point,
                    source: 'meeting_summary',
                    anchor: "summary.key_points.{$index}",
                );
            }
        }

        $review = $event->meetingReview;
        if ($review?->key_insight) {
            $items[] = $this->takeaway(
                id: "review-key-insight-{$review->id}",
                kind: 'info',
                label: 'Insight',
                title: (string) $review->key_insight,
                excerpt: $this->excerpt((string) $review->key_insight),
                fullText: (string) $review->key_insight,
                source: 'meeting_review',
                anchor: 'review.key_insight',
            );
        }

        foreach (array_values($review?->suggestions ?? []) as $index => $suggestion) {
            $items[] = $this->takeaway(
                id: "review-suggestion-{$review->id}-{$index}",
                kind: 'action',
                label: 'Action',
                title: (string) $suggestion,
                excerpt: $this->excerpt((string) $suggestion),
                fullText: (string) $suggestion,
                source: 'meeting_review',
                anchor: "review.suggestions.{$index}",
            );
        }

        foreach ($event->tasks as $index => $task) {
            $items[] = $this->takeaway(
                id: "task-{$task->id}",
                kind: 'task',
                label: $task->status === 'done' ? 'Done' : 'Action',
                title: (string) $task->name,
                excerpt: $this->taskExcerpt($task),
                fullText: $this->taskFullText($task),
                source: 'tasks',
                anchor: "tasks.{$index}",
            );
        }

        return $items;
    }

    private function takeaway(
        string $id,
        string $kind,
        string $label,
        string $title,
        string $excerpt,
        string $fullText,
        string $source,
        string $anchor,
    ): array {
        return [
            'id' => $id,
            'kind' => $kind,
            'label' => $label,
            'title' => $title,
            'excerpt' => $excerpt,
            'full_text' => $fullText,
            'source' => $source,
            'read_more' => [
                'section' => $source,
                'anchor' => $anchor,
            ],
        ];
    }

    private function excerpt(string $text, int $limit = 140): string
    {
        return Str::limit(preg_replace('/\s+/', ' ', trim($text)) ?? $text, $limit);
    }

    private function resolveTemplate(CalendarEvent $event): ?AgendaTemplate
    {
        $teamId = $event->source?->user?->teams?->first()?->id;
        if (! $teamId) {
            return null;
        }

        return AgendaTemplate::where('team_id', $teamId)->first();
    }

    private function taskExcerpt(mixed $task): string
    {
        $parts = [];

        if ($task->assignee_name) {
            $parts[] = $task->assignee_name;
        }

        if ($task->description) {
            $parts[] = (string) $task->description;
        }

        if ($task->due_date) {
            $parts[] = 'Due: '.$task->due_date->toDateString();
        }

        if ($task->status) {
            $parts[] = 'Status: '.$task->status;
        }

        return $this->excerpt(implode(' · ', $parts) ?: $task->name);
    }

    private function taskFullText(mixed $task): string
    {
        $parts = [
            (string) $task->name,
        ];

        if ($task->assignee_name) {
            $parts[] = 'Assignee: '.$task->assignee_name;
        }

        if ($task->description) {
            $parts[] = 'Description: '.$task->description;
        }

        if ($task->due_date) {
            $parts[] = 'Due date: '.$task->due_date->toDateString();
        }

        if ($task->status) {
            $parts[] = 'Status: '.$task->status;
        }

        return implode("\n", $parts);
    }
}
