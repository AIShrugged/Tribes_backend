<?php

namespace App\Services\Meeting;

use App\Domain\DTO\AI\MessageDTO;
use App\Models\AgentActivityLog;
use App\Enums\MeetingTaskStatus;
use App\Events\MeetingTasksExtracted;
use App\Models\CalendarEvent;
use App\Models\Issue;
use App\Services\CalendarEventOrganizationResolver;
use App\Services\Followup\TranscriptBuilderService;
use App\Models\Setting;
use App\Services\OpenRouterClient;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class MeetingTaskService
{
    public function __construct(
        private readonly OpenRouterClient $llm,
        private readonly TranscriptBuilderService $transcriptBuilder,
        private readonly ParticipantProfileMatchingService $participantMatcher,
        private readonly CalendarEventOrganizationResolver $organizationResolver,
    ) {
    }

    public function extract(CalendarEvent $event): Collection
    {
        // Match participants to profiles first so the LLM can reference profile_id directly
        $this->participantMatcher->match($event);

        $participants = $event->participants()->with('profile')->whereNotNull('profile_id')->get();
        $transcript   = $this->transcriptBuilder->build($event);
        $assigneeByProfileId = $participants
            ->filter(fn ($participant) => $participant->profile?->user_id)
            ->mapWithKeys(fn ($participant) => [$participant->profile_id => $participant->profile->user_id])
            ->all();

        $resolved   = $this->organizationResolver->resolve($event);
        $orgContext = $resolved ? $resolved['team']->organization?->context : null;

        try {
            $json = $this->llm->chat(
                messages: [new MessageDTO('user', $this->buildPrompt($transcript, $participants, $orgContext))],
                model: Setting::get('model.meeting_tasks', config('ai.providers.anthropic.models.meeting_tasks')),
                maxTokens: 4096,
                forceJsonResponse: true,
            );

            $items = json_decode($json, true);

            if (!is_array($items)) {
                return new Collection();
            }

            $event->issues()->forceDelete();

            foreach ($items as $item) {
                Issue::create([
                    'user_id' => $event->source?->user_id,
                'sourceable_type' => CalendarEvent::class,
                'sourceable_id'   => $event->id,
                'name'          => $item['title'],
                'description'   => $item['description'] ?? null,
                'assignee_name' => $item['assignee_name'] ?? null,
                'assignee_id'   => isset($item['profile_id']) ? ($assigneeByProfileId[$item['profile_id']] ?? null) : null,
                'due_date'      => $item['due_date'] ?? null,
                'type' => Issue::normalizeType($item['type'] ?? null) ?? Issue::TYPE_DEVELOPMENT,
                'status'        => MeetingTaskStatus::OPEN->value,
            ]);
            }

            $issues = $event->issues()->get();
            if ($issues->isNotEmpty()) {
                MeetingTasksExtracted::dispatch($event, $issues);
            }

            if ($event->source?->user) {
                AgentActivityLog::recordActivity(
                    user: $event->source->user,
                    toolName: 'meeting_tasks_extracted',
                    toolResult: [
                        'count' => $issues->count(),
                        'event_id' => $event->id,
                        'calendar_event_id' => $event->id,
                    ],
                );
            }
        } catch (\Throwable $e) {
            Log::error('MeetingTaskService: extraction failed', ['error' => $e->getMessage()]);
        }

        return $event->issues()->get();
    }

    private function buildPrompt(string $transcript, Collection $participants, ?string $orgContext = null): string
    {
        $participantsBlock = '';

        if ($participants->isNotEmpty()) {
            $list = $participants->map(fn($p) => "- profile_id={$p->profile_id}, name=\"{$p->name}\"")->implode("\n");
            $participantsBlock = <<<BLOCK

            Known meeting participants with their profile IDs (use profile_id when you can identify the assignee):
            {$list}

            BLOCK;
        }

        $contextBlock = $orgContext
            ? "\n## Organization context\n\nUse this to better understand the domain, team roles, and terminology when extracting tasks:\n\n{$orgContext}\n"
            : '';

        return <<<TXT
        Analyze the meeting transcript and extract all tasks, assignments, and action items.{$contextBlock}
        Return a JSON array of tasks in the following format:
        [
            {
                "title": "Short task title",
                "description": "Detailed description or null",
                "assignee_name": "Assignee name as mentioned in the transcript, or null",
                "profile_id": 5,
                "type": "frontend | backend | organization",
                "due_date": "YYYY-MM-DD or null"
            }
        ]
        {$participantsBlock}
        Rules:
        - Set profile_id only if you can confidently match the assignee to one of the known participants above
        - If the assignee is unknown or not in the participants list — set profile_id to null
        - Use "frontend" for UI/web app tasks, "backend" for APIs/services/data/integrations, and "organization" for coordination or process work
        - If there are no tasks — return an empty array []
        - Respond with valid JSON only, no additional text

        Meeting transcript:
        {$transcript}
        TXT;
    }
}
