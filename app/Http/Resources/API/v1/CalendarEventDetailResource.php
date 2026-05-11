<?php

namespace App\Http\Resources\API\v1;

use App\Models\CalendarEvent;
use App\Services\Agenda\AgendaRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
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
            'agendas' => $event->agendas
                ->map(fn ($agenda) => [
                    'id' => $agenda->id,
                    'type' => $agenda->type,
                    'status' => $agenda->status,
                    'content' => $agenda->isGeneral() && !empty($agenda->raw_json)
                        ? AgendaRenderer::renderForWeb($agenda->raw_json, $event)
                        : $agenda->content,
                    'user_id' => $agenda->user_id,
                    'sent_at' => $agenda->sent_at,
                    'send_scheduled_at' => $agenda->send_scheduled_at,
                ])
                ->values()
                ->all(),
            'tasks' => MeetingTaskResource::collection($event->tasks)->resolve($request),
            'summary' => $event->meetingSummary
                ? MeetingSummaryResource::make($event->meetingSummary)->resolve($request)
                : null,
            'review' => $event->meetingReview
                ? MeetingReviewResource::make($event->meetingReview)->resolve($request)
                : null,
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
