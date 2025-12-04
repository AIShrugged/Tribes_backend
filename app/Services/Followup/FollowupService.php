<?php

namespace App\Services\Followup;

use App\Domain\DTO\AI\MessageDTO;
use App\Enums\FollowupStatus;
use App\Models\CalendarEvent;
use App\Models\Followup;
use App\Models\Participant;
use App\Services\OpenRouterClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FollowupService
{
    public function __construct(
        private readonly OpenRouterClient $llm,
        private readonly FollowupPromptRegistry $promptRegistry
    ) {
    }

    public function generate(
        CalendarEvent $event,
        string $scope,
        string $type,
        ?Participant $participant = null
    ): Followup {
        return DB::transaction(function () use ($event, $scope, $type, $participant) {
            $followup = Followup::create([
                'calendar_event_id' => $event->id,
                'participant_id'    => $participant?->id,
                'scope'             => $scope,
                'type'              => $type,
                'status'            => FollowupStatus::IN_PROGRESS->value,
                'text'              => '',
            ]);

            try {
                $prompt = $this->promptRegistry->resolve($scope, $type);

                $transcript = $this->buildTranscript($event);

                $messages = [
                    new MessageDTO('system', $prompt->getSystemPrompt()),
                    new MessageDTO('user', $prompt->getUserPrompt() . $transcript),
                ];

                Log::info('messages', $messages);

                $json = $this->llm->chat(
                    messages: $messages,
                    model: config('ai.providers.openrouter.models.followup'),
                    maxTokens: 4096,
                    forceJsonResponse: true
                );

                $followup->update([
                    'status' => FollowupStatus::DONE->value,
                    'text'   => $json,
                ]);
            } catch (\Throwable $e) {
                $followup->update([
                    'status' => FollowupStatus::FAILED->value,
                ]);
            }

            return $followup;
        });
    }

    private function buildTranscript(CalendarEvent $event): string
    {
        $query = $event->transcriptEntries()
            ->orderBy('start_absolute');


        return $query->get()
            ->map(function ($entry) {
                return sprintf(
                    "%s %s: %s",
                    $entry->start_relative,
                    $entry->participant?->name ?? 'Unknown',
                    $entry->text,
                );
            })
            ->implode("\n");
    }
}
