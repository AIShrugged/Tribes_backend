<?php

namespace App\Services\Insight;

use App\Domain\DTO\AI\MessageDTO;
use App\Models\AgentActivityLog;
use App\Models\CalendarEvent;
use App\Models\InsightRelationship;
use App\Models\Profile;
use App\Models\User;
use App\Services\Followup\TranscriptBuilderService;
use App\Models\Setting;
use App\Services\OpenRouterClient;
use Illuminate\Support\Facades\Log;

class InsightRelationshipService
{
    public function __construct(
        private readonly OpenRouterClient $llm,
        private readonly TranscriptBuilderService $transcriptBuilder,
        private readonly InsightPromptBuilder $promptBuilder,
    ) {}

    /**
     * Process all pair relationships for a meeting.
     *
     * @param  int[]  $profileIds
     */
    public function processFromEvent(CalendarEvent $event, array $profileIds): void
    {
        $transcript = $this->transcriptBuilder->build($event);

        if (empty(trim($transcript))) {
            return;
        }

        $profiles = Profile::whereIn('id', array_unique($profileIds))
            ->get()
            ->keyBy('id');

        $pairs = $this->buildPairs(array_unique($profileIds));

        foreach ($pairs as [$idA, $idB]) {
            $profileA = $profiles[$idA] ?? null;
            $profileB = $profiles[$idB] ?? null;

            if (!$profileA || !$profileB) {
                continue;
            }

            $this->evolvePair($profileA, $profileB, $transcript, $event, $event->source?->user);
        }
    }

    /**
     * Evolve the relationship record for a specific pair.
     */
    public function evolvePair(Profile $profileA, Profile $profileB, string $transcript, CalendarEvent $event, ?User $user = null): void
    {
        [$idA, $idB] = InsightRelationship::sortIds($profileA->id, $profileB->id);

        $existing = InsightRelationship::findPair($idA, $idB);

        $observation = $this->extractPairObservation($profileA, $profileB, $transcript, $event);

        if ($observation === null) {
            return;
        }

        $updatedDynamics = $this->callEvolutionLLM(
            identifierA:         $profileA->channel_identifier,
            identifierB:         $profileB->channel_identifier,
            existingDynamics:    $existing?->dynamics ?? [],
            newObservation:      $observation['observation'],
            newRelationshipType: $observation['relationship_type'],
        );

        if ($updatedDynamics === null) {
            return;
        }

        InsightRelationship::updateOrCreate(
            ['profile_id_a' => $idA, 'profile_id_b' => $idB],
            [
                'dynamics'            => $updatedDynamics,
                'relationship_type'   => $updatedDynamics['relationship_type'] ?? 'neutral',
                'interaction_count'   => ($existing?->interaction_count ?? 0) + 1,
                'last_interaction_at' => now(),
            ],
        );

        if ($user) {
            AgentActivityLog::recordActivity(
                user: $user,
                toolName: 'insight_relationship_updated',
                toolResult: [
                    'profile_id_a' => $profileA->id,
                    'profile_id_b' => $profileB->id,
                    'category' => 'relationship',
                    'count' => 1,
                ],
            );
        }
    }

    /**
     * Build unique ordered pairs from profile ID list.
     *
     * @param  int[]  $profileIds
     * @return array<array{int, int}>
     */
    private function buildPairs(array $profileIds): array
    {
        $pairs = [];

        for ($i = 0; $i < count($profileIds); $i++) {
            for ($j = $i + 1; $j < count($profileIds); $j++) {
                [$a, $b] = InsightRelationship::sortIds($profileIds[$i], $profileIds[$j]);
                $pairs[] = [$a, $b];
            }
        }

        return $pairs;
    }

    private function extractPairObservation(Profile $profileA, Profile $profileB, string $transcript, CalendarEvent $event): ?array
    {
        $identifierA = $profileA->channel_identifier;
        $identifierB = $profileB->channel_identifier;
        $meetingDate = $event->starts_at ? \Carbon\Carbon::parse($event->starts_at)->toDateString() : 'Unknown';

        try {
            $prompt = <<<PROMPT
Analyze the following meeting transcript and describe the interaction between exactly TWO people.

Person A: {$identifierA}
Person B: {$identifierB}

Meeting: {$event->title}
Date: {$meetingDate}

If there was no meaningful interaction between these two people in this transcript, return:
{"no_interaction": true}

Otherwise return:
{
  "observation": "one concise sentence describing how they interacted",
  "relationship_type": "collaborative|conflicting|hierarchical|neutral"
}

Return ONLY valid JSON. No explanation.

Transcript:
{$transcript}
PROMPT;

            $json = $this->llm->chat(
                messages:          [new MessageDTO('user', $prompt)],
                model:             Setting::get('model.insight', config('ai.providers.anthropic.models.insight')),
                maxTokens:         2048,
                forceJsonResponse: true,
            );

            $data = json_decode($json, true);

            if (!is_array($data) || isset($data['no_interaction'])) {
                return null;
            }

            return $data;
        } catch (\Throwable $e) {
            Log::error('InsightRelationshipService: pair observation failed', [
                'profile_id_a' => $profileA->id,
                'profile_id_b' => $profileB->id,
                'error'        => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function callEvolutionLLM(
        string $identifierA,
        string $identifierB,
        array $existingDynamics,
        string $newObservation,
        string $newRelationshipType,
    ): ?array {
        try {
            $prompt = $this->promptBuilder->buildRelationshipEvolutionPrompt(
                nameA:               $identifierA,
                nameB:               $identifierB,
                existingDynamics:    $existingDynamics,
                newObservation:      $newObservation,
                newRelationshipType: $newRelationshipType,
            );

            $json = $this->llm->chat(
                messages:          [new MessageDTO('user', $prompt)],
                model:             Setting::get('model.insight', config('ai.providers.anthropic.models.insight')),
                maxTokens:         1024,
                forceJsonResponse: true,
            );

            $data = json_decode($json, true);

            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            Log::error('InsightRelationshipService: evolution LLM failed', [
                'identifier_a' => $identifierA,
                'identifier_b' => $identifierB,
                'error'        => $e->getMessage(),
            ]);
            return null;
        }
    }
}
