<?php

namespace App\Services\Meeting;

use App\Domain\DTO\AI\MessageDTO;
use App\Models\CalendarEvent;
use App\Models\AgentActivityLog;
use App\Models\Channel;
use App\Models\Profile;
use App\Models\Setting;
use App\Services\OpenRouterClient;
use Illuminate\Support\Facades\Log;

/**
 * @deprecated Not wired into the production pipeline. `participant.profile_id` is therefore
 *             null in prod, and consumers should resolve participants to users via the
 *             cascade in {@see \App\Services\Decisions\DecisionAuthorResolver} instead
 *             (4 paths: participant.profile_id → event-profile-pivot by name → orphan-GC
 *             profile by email → global user name match).
 *
 *             Tracked in docs/transcript-pipeline-refactor.md (ADR-6). Either re-wire this
 *             service as a pre-step before TranscriptParsed listeners, or delete it entirely.
 *             Until then: do NOT add new callers.
 */
class ParticipantProfileMatchingService
{
    public function __construct(
        private readonly OpenRouterClient $llm,
    ) {
    }

    /**
     * Matches participants of the event to system profiles via LLM.
     * Updates participant.profile_id, profile_confidence, profile_matched_by.
     *
     * @deprecated See class-level note.
     */
    public function match(CalendarEvent $event): void
    {
        $participants = $event->participants()->whereNull('profile_id')->get();

        if ($participants->isEmpty()) {
            return;
        }

        $profiles = $event->profiles()->with(['user', 'channel'])->get();

        if ($profiles->isEmpty()) {
            return;
        }

        try {
            $json = $this->llm->chat(
                messages: [new MessageDTO('user', $this->buildPrompt($participants, $profiles))],
                model: Setting::get('model.meeting_tasks', config('ai.providers.openrouter.models.meeting_tasks')),
                maxTokens: 2048,
                forceJsonResponse: true,
            );

            $matches = json_decode($json, true);

            if (!is_array($matches)) {
                return;
            }

            $matchedCount = 0;

            foreach ($matches as $match) {
                $participantId = $match['participant_id'] ?? null;
                $profileId     = $match['profile_id'] ?? null;
                $confidence    = isset($match['confidence']) ? (int) $match['confidence'] : 0;

                if (!$participantId) {
                    continue;
                }

                $participant = $participants->firstWhere('id', $participantId);

                if (!$participant) {
                    continue;
                }

                $participant->update([
                    'profile_id'          => $profileId,
                    'profile_confidence'  => $profileId ? $confidence : null,
                    'profile_matched_by'  => $profileId ? 'ai' : null,
                ]);

                if ($profileId) {
                    $matchedCount++;
                }
            }

            if ($event->source?->user) {
                AgentActivityLog::recordActivity(
                    user: $event->source->user,
                    toolName: 'participant_profiles_matched',
                    toolResult: [
                        'count' => $matchedCount,
                        'calendar_event_id' => $event->id,
                    ],
                );
            }
        } catch (\Throwable $e) {
            Log::error('ParticipantProfileMatchingService: matching failed', [
                'event_id' => $event->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    private function loadProfiles(): \Illuminate\Database\Eloquent\Collection
    {
        return Profile::with(['user', 'channel'])->get();
    }

    private function buildPrompt(
        \Illuminate\Database\Eloquent\Collection $participants,
        \Illuminate\Database\Eloquent\Collection $profiles,
    ): string {
        $participantsJson = $participants->map(fn($p) => [
            'id'   => $p->id,
            'name' => $p->name,
        ])->values()->toJson(JSON_UNESCAPED_UNICODE);

        $googleCalendarChannelId = Channel::idFor('google_calendar');

        $profilesData = $profiles->map(function ($profile) use ($googleCalendarChannelId) {
            $data = [
                'id'   => $profile->id,
                'name' => $profile->user?->name,
            ];

            // For google_calendar channel, identifier = email
            if ($profile->channel_id === $googleCalendarChannelId) {
                $data['email'] = $profile->channel_identifier;
            }

            // User email as fallback
            if (empty($data['email']) && $profile->user?->email) {
                $data['email'] = $profile->user->email;
            }

            return $data;
        })->values()->toJson(JSON_UNESCAPED_UNICODE);

        return <<<TXT
        You are given a list of meeting participants and a list of registered profiles in the system.
        Match each participant to the most suitable profile based on name and email.

        Meeting participants:
        {$participantsJson}

        System profiles:
        {$profilesData}

        Return a JSON array in the following format:
        [
            {"participant_id": 1, "profile_id": 5, "confidence": 95},
            {"participant_id": 2, "profile_id": null, "confidence": 0}
        ]

        Rules:
        - confidence is from 0 to 100, where 100 = full certainty
        - If no matching profile exists — use profile_id: null, confidence: 0
        - Every participant must be present in the response
        - One profile can be matched to only one participant
        - Respond with valid JSON only, no additional text
        TXT;
    }
}
