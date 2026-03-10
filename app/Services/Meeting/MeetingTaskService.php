<?php

namespace App\Services\Meeting;

use App\Domain\DTO\AI\MessageDTO;
use App\Enums\MeetingTaskStatus;
use App\Models\CalendarEvent;
use App\Models\Task;
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
    ) {
    }

    public function extract(CalendarEvent $event): Collection
    {
        // Match participants to profiles first so the LLM can reference profile_id directly
        $this->participantMatcher->match($event);

        $participants = $event->participants()->whereNotNull('profile_id')->get();
        $transcript   = $this->transcriptBuilder->build($event);

        try {
            $json = $this->llm->chat(
                messages: [new MessageDTO('user', $this->buildPrompt($transcript, $participants))],
                model: Setting::get('model.meeting_tasks', config('ai.providers.openrouter.models.meeting_tasks')),
                maxTokens: 4096,
                forceJsonResponse: true,
            );

            $items = json_decode($json, true);

            if (!is_array($items)) {
                return new Collection();
            }

            $event->tasks()->delete();

            foreach ($items as $item) {
                Task::create([
                    'taskable_type' => CalendarEvent::class,
                    'taskable_id'   => $event->id,
                    'title'         => $item['title'],
                    'description'   => $item['description'] ?? null,
                    'assignee_name' => $item['assignee_name'] ?? null,
                    'profile_id'    => $item['profile_id'] ?? null,
                    'due_date'      => $item['due_date'] ?? null,
                    'status'        => MeetingTaskStatus::OPEN->value,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('MeetingTaskService: extraction failed', ['error' => $e->getMessage()]);
        }

        return $event->tasks()->get();
    }

    private function buildPrompt(string $transcript, Collection $participants): string
    {
        $participantsBlock = '';

        if ($participants->isNotEmpty()) {
            $list = $participants->map(fn($p) => "- profile_id={$p->profile_id}, name=\"{$p->name}\"")->implode("\n");
            $participantsBlock = <<<BLOCK

            Known meeting participants with their profile IDs (use profile_id when you can identify the assignee):
            {$list}

            BLOCK;
        }

        return <<<TXT
        Analyze the meeting transcript and extract all tasks, assignments, and action items.
        Return a JSON array of tasks in the following format:
        [
            {
                "title": "Short task title",
                "description": "Detailed description or null",
                "assignee_name": "Assignee name as mentioned in the transcript, or null",
                "profile_id": 5,
                "due_date": "YYYY-MM-DD or null"
            }
        ]
        {$participantsBlock}
        Rules:
        - Set profile_id only if you can confidently match the assignee to one of the known participants above
        - If the assignee is unknown or not in the participants list — set profile_id to null
        - If there are no tasks — return an empty array []
        - Respond with valid JSON only, no additional text

        Meeting transcript:
        {$transcript}
        TXT;
    }
}
