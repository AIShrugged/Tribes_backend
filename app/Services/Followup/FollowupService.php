<?php

namespace App\Services\Followup;

use App\Domain\DTO\AI\MessageDTO;
use App\Enums\FollowupStatus;
use App\Models\CalendarEvent;
use App\Models\Followup;
use App\Models\Methodology;
use App\Models\Participant;
use App\Services\OpenRouterClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FollowupService
{
    public function __construct(
        private readonly OpenRouterClient $llm,
        private readonly TranscriptBuilderService $transcriptBuilder,
    ) {
    }

    public function generate(
        CalendarEvent $event,
        string $scope,
        ?Participant $participant = null
    ): Followup {
        return DB::transaction(function () use ($event, $scope, $participant) {
            //TODO: get team from the user, then use methodology assigned to it
            $methodology = Methodology::getDefault();

            $followup = Followup::create([
                'calendar_event_id' => $event->id,
                'participant_id'    => $participant?->id,
                'methodology_id'    => $methodology->id,
                'scope'             => $scope,
                'status'            => FollowupStatus::IN_PROGRESS->value,
                'text'              => '',
            ]);

            try {

                $transcript = $this->transcriptBuilder->build($event);

                $messages = [
                    new MessageDTO('user', view('prompts.methodology_prompt', ['scheme' => $methodology->scheme])->render()),
                    new MessageDTO('user', "Текст с методикой:\n" . $methodology->text),
                    new MessageDTO('user', "Транскрипт встречи:\n" . $transcript),
                ];

                Log::info('messages', $messages);

                $json = $this->llm->chat(
                    messages: $messages,
                    model: config('ai.providers.openrouter.models.followup'),
                    maxTokens: 8192,
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

}
