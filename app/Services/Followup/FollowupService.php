<?php

namespace App\Services\Followup;

use App\Domain\DTO\AI\MessageDTO;
use App\Enums\FollowupStatus;
use App\Services\Artifact\ArtifactSchema;
use App\Models\AgentActivityLog;
use App\Models\CalendarEvent;
use App\Models\Followup;
use App\Models\Methodology;
use App\Models\Team;
use App\Models\User;
use App\Models\Setting;
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

            $this->generateContent($followup, $methodology, $user);

            return $followup;
        });
    }

    /**
     * Regenerate an existing followup using the team's current methodology.
     * Creates a new followup record, leaving the old one intact.
     */
    public function regenerate(Followup $followup, User $user): Followup
    {
        $team = $followup->team;
        $event = $followup->calendarEvent;
        $methodology = $team->methodology ?? Methodology::getDefault();

        return DB::transaction(function () use ($event, $team, $user, $methodology) {
            $newFollowup = Followup::create([
                'calendar_event_id' => $event->id,
                'team_id'           => $team->id,
                'user_id'           => $user->id,
                'methodology_id'    => $methodology->id,
                'status'            => FollowupStatus::IN_PROGRESS->value,
                'text'              => '',
            ]);

            $this->generateContent($newFollowup, $methodology, $user);

            return $newFollowup;
        });
    }

    private function generateContent(Followup $followup, Methodology $methodology, User $user): void
    {
        try {
            $event = $followup->calendarEvent;
            $transcript = $this->transcriptBuilder->build($event);
            $artifactId = 'followup_'.$followup->id;
            $artifactTitle = $event->title
                ? "Followup #{$followup->id} — {$event->title}"
                : "Followup #{$followup->id}";

            $messages = [
                new MessageDTO('user', view('prompts.methodology_prompt', [
                    'methodology' => $methodology->text,
                    'transcript' => $transcript,
                    'artifact_id' => $artifactId,
                    'artifact_title' => $artifactTitle,
                    'artifact_schema' => ArtifactSchema::dataDescription(),
                ])->render()),
            ];

            Log::info('messages', $messages);

            $json = $this->llm->chat(
                messages: $messages,
                model: Setting::get('model.followup', config('ai.providers.openrouter.models.followup')),
                maxTokens: 8192,
                forceJsonResponse: true
            );

            $followup->update([
                'status' => FollowupStatus::DONE->value,
                'text'   => $json,
            ]);

            AgentActivityLog::recordActivity(
                user: $user,
                toolName: 'followup_generated',
                toolResult: [
                    'followup_id' => $followup->id,
                    'calendar_event_id' => $event->id,
                ],
            );
        } catch (\Throwable $e) {
            $followup->update([
                'status' => FollowupStatus::FAILED->value,
            ]);

            Log::error('Followup generation failed', [
                'followup_id' => $followup->id,
                'team_id' => $followup->team_id,
                'error' => $e->getMessage()
            ]);
        }
    }
}
