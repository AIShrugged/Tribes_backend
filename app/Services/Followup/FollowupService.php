<?php

namespace App\Services\Followup;

use App\Domain\DTO\AI\MessageDTO;
use App\Enums\FollowupStatus;
use App\Models\CalendarEvent;
use App\Models\Followup;
use App\Models\Methodology;
use App\Models\Team;
use App\Models\User;
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
        Team $team,
        User $user
    ): Followup {
        return DB::transaction(function () use ($event, $team, $user) {
            // Получить методологию команды или дефолтную
            $methodology = $team->methodology ?? Methodology::getDefault();

            $followup = Followup::create([
                'calendar_event_id' => $event->id,
                'team_id'           => $team->id,
                'user_id'           => $user->id,
                'methodology_id'    => $methodology->id,
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

                Log::error('Followup generation failed', [
                    'followup_id' => $followup->id,
                    'team_id' => $team->id,
                    'error' => $e->getMessage()
                ]);
            }

            return $followup;
        });
    }

}
