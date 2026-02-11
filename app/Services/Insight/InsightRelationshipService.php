<?php

namespace App\Services\Insight;

use App\Domain\DTO\AI\MessageDTO;
use App\Models\CalendarEvent;
use App\Models\InsightRelationship;
use App\Services\Followup\TranscriptBuilderService;
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
     * @param  string[]  $emails
     */
    public function processFromEvent(CalendarEvent $event, array $emails): void
    {
        $transcript = $this->transcriptBuilder->build($event);

        if (empty(trim($transcript))) {
            return;
        }

        $pairs = $this->buildPairs($emails);

        foreach ($pairs as [$emailA, $emailB]) {
            $this->evolvePair($emailA, $emailB, $transcript, $event);
        }
    }

    /**
     * Evolve the relationship record for a specific pair.
     */
    public function evolvePair(string $emailA, string $emailB, string $transcript, CalendarEvent $event): void
    {
        [$a, $b] = InsightRelationship::sortEmails($emailA, $emailB);

        $existing = InsightRelationship::findPair($a, $b);

        $observation = $this->extractPairObservation($a, $b, $transcript, $event);

        if ($observation === null) {
            return;
        }

        $updatedDynamics = $this->callEvolutionLLM(
            nameA:               $a,
            nameB:               $b,
            existingDynamics:    $existing?->dynamics ?? [],
            newObservation:      $observation['observation'],
            newRelationshipType: $observation['relationship_type'],
        );

        if ($updatedDynamics === null) {
            return;
        }

        InsightRelationship::updateOrCreate(
            ['email_a' => $a, 'email_b' => $b],
            [
                'dynamics'            => $updatedDynamics,
                'relationship_type'   => $updatedDynamics['relationship_type'] ?? 'neutral',
                'interaction_count'   => ($existing?->interaction_count ?? 0) + 1,
                'last_interaction_at' => now(),
            ],
        );
    }

    /**
     * Build unique ordered pairs from email list.
     *
     * @return array<array{string, string}>
     */
    private function buildPairs(array $emails): array
    {
        $pairs = [];
        $emails = array_unique($emails);

        for ($i = 0; $i < count($emails); $i++) {
            for ($j = $i + 1; $j < count($emails); $j++) {
                [$a, $b] = InsightRelationship::sortEmails($emails[$i], $emails[$j]);
                $pairs[] = [$a, $b];
            }
        }

        return $pairs;
    }

    private function extractPairObservation(string $emailA, string $emailB, string $transcript, CalendarEvent $event): ?array
    {
        try {
            $meetingDate = $event->starts_at ? \Carbon\Carbon::parse($event->starts_at)->toDateString() : 'Unknown';
            $prompt = <<<PROMPT
Analyze the following meeting transcript and describe the interaction between exactly TWO people identified by their emails.

Person A email: {$emailA}
Person B email: {$emailB}

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
                messages:         [new MessageDTO('user', $prompt)],
                model:            config('ai.providers.openrouter.models.insight'),
                maxTokens:        2048,
                forceJsonResponse: true,
            );

            $data = json_decode($json, true);

            if (!is_array($data) || isset($data['no_interaction'])) {
                return null;
            }

            return $data;
        } catch (\Throwable $e) {
            Log::error('InsightRelationshipService: pair observation failed', [
                'email_a' => $emailA,
                'email_b' => $emailB,
                'error'   => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function callEvolutionLLM(
        string $nameA,
        string $nameB,
        array $existingDynamics,
        string $newObservation,
        string $newRelationshipType,
    ): ?array {
        try {
            $prompt = $this->promptBuilder->buildRelationshipEvolutionPrompt(
                nameA:               $nameA,
                nameB:               $nameB,
                existingDynamics:    $existingDynamics,
                newObservation:      $newObservation,
                newRelationshipType: $newRelationshipType,
            );

            $json = $this->llm->chat(
                messages:         [new MessageDTO('user', $prompt)],
                model:            config('ai.providers.openrouter.models.insight'),
                maxTokens:        1024,
                forceJsonResponse: true,
            );

            $data = json_decode($json, true);

            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            Log::error('InsightRelationshipService: evolution LLM failed', [
                'email_a' => $nameA,
                'email_b' => $nameB,
                'error'   => $e->getMessage(),
            ]);
            return null;
        }
    }
}
