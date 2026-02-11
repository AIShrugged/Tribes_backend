<?php

namespace App\Services\Insight;

use App\Domain\DTO\AI\MessageDTO;
use App\Domain\DTO\Insight\InsightExtractedDataDTO;
use App\Models\CalendarEvent;
use App\Models\InsightSource;
use App\Models\Participant;
use App\Services\Followup\TranscriptBuilderService;
use App\Services\OpenRouterClient;
use Illuminate\Support\Facades\Log;

class InsightExtractionService
{
    public function __construct(
        private readonly OpenRouterClient $llm,
        private readonly TranscriptBuilderService $transcriptBuilder,
        private readonly InsightPromptBuilder $promptBuilder,
    ) {}

    /**
     * Extract insights from a transcript and persist raw items.
     * Returns one InsightSource per participant that was successfully processed.
     *
     * @return InsightSource[]
     */
    public function extract(CalendarEvent $event): array
    {
        $transcript = $this->transcriptBuilder->build($event);

        if (empty(trim($transcript))) {
            Log::info('InsightExtractionService: empty transcript, skipping', ['event_id' => $event->id]);
            return [];
        }

        $participantMap = $this->buildParticipantMap($event);

        if (empty($participantMap)) {
            Log::info('InsightExtractionService: no participants with emails, skipping', ['event_id' => $event->id]);
            return [];
        }

        $extractedData = $this->callLLM($event, $transcript, $participantMap);

        if ($extractedData === null) {
            return [];
        }

        return $this->persist($event, $extractedData);
    }

    /**
     * Build a name => email map for participants with known profiles.
     *
     * @return array<string, string>
     */
    private function buildParticipantMap(CalendarEvent $event): array
    {
        return $event->participants()
            ->with('profile')
            ->get()
            ->filter(fn(Participant $p) => $p->profile?->email)
            ->mapWithKeys(fn(Participant $p) => [$p->name => $p->profile->email])
            ->toArray();
    }

    private function callLLM(CalendarEvent $event, string $transcript, array $participantMap): ?InsightExtractedDataDTO
    {
        try {
            $prompt = $this->promptBuilder->buildExtractionPrompt(
                transcript:    $transcript,
                participantMap: $participantMap,
                meetingTitle:  $event->title ?? 'Meeting',
                meetingDate:   $event->starts_at ? \Carbon\Carbon::parse($event->starts_at)->toDateString() : now()->toDateString(),
            );

            $json = $this->llm->chat(
                messages:         [new MessageDTO('user', $prompt)],
                model:            config('ai.providers.openrouter.models.insight'),
                maxTokens:        4096,
                forceJsonResponse: true,
            );

            $data = json_decode($json, true);

            if (!isset($data['participants'])) {
                Log::warning('InsightExtractionService: unexpected LLM response structure', ['event_id' => $event->id]);
                return null;
            }

            return InsightExtractedDataDTO::fromArray($data);
        } catch (\Throwable $e) {
            Log::error('InsightExtractionService: LLM call failed', [
                'event_id' => $event->id,
                'error'    => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Persist extracted data to DB. Creates InsightSource + InsightItems + InsightShortTerm per participant.
     *
     * @return InsightSource[]
     */
    private function persist(CalendarEvent $event, InsightExtractedDataDTO $data): array
    {
        $sources = [];

        foreach ($data->participants as $participant) {
            $source = InsightSource::firstOrCreate(
                [
                    'email'       => $participant->email,
                    'source_type' => 'transcript',
                    'source_id'   => $event->id,
                ],
                ['processed_at' => now()],
            );

            // Persist atomic items
            foreach ($participant->items as $item) {
                $source->items()->create([
                    'email'      => $participant->email,
                    'category'   => $item->category,
                    'fact'       => $item->fact,
                    'confidence' => $item->confidence,
                ]);
            }

            // Persist short-term memory (30 days TTL, emotional_state — 7 days)
            foreach ($participant->shortTerm as $shortTerm) {
                $ttlDays = $shortTerm->contextType === 'emotional_state' ? 7 : 30;

                $source->shortTermMemories()->create([
                    'email'        => $participant->email,
                    'context_type' => $shortTerm->contextType,
                    'content'      => $shortTerm->content,
                    'expires_at'   => now()->addDays($ttlDays),
                ]);
            }

            $source->update(['processed_at' => now()]);
            $sources[] = $source;
        }

        return $sources;
    }
}
