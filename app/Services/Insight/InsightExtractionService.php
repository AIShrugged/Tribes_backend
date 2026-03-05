<?php

namespace App\Services\Insight;

use App\Domain\DTO\AI\MessageDTO;
use App\Domain\DTO\Insight\InsightExtractedDataDTO;
use App\Models\CalendarEvent;
use App\Models\Channel;
use App\Models\InsightSource;
use App\Models\Participant;
use App\Models\Profile;
use App\Services\Followup\TranscriptBuilderService;
use App\Models\Setting;
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

        // name => profile map for participants with known profiles
        $profileMap = $this->buildProfileMap($event);

        if (empty($profileMap)) {
            Log::info('InsightExtractionService: no participants with profiles, skipping', ['event_id' => $event->id]);
            return [];
        }

        // name => identifier map for the LLM prompt
        $participantMap = array_map(
            fn(Profile $p) => $p->channel_identifier,
            $profileMap
        );

        $extractedData = $this->callLLM($event, $transcript, $participantMap);

        if ($extractedData === null) {
            return [];
        }

        return $this->persist($event, $extractedData, $profileMap);
    }

    /**
     * Build a name => Profile map for participants with known google_calendar profiles.
     *
     * @return array<string, Profile>
     */
    private function buildProfileMap(CalendarEvent $event): array
    {
        $gcChannelId = Channel::idFor('google_calendar');

        if (!$gcChannelId) {
            return [];
        }

        return $event->participants()
            ->with('profile')
            ->get()
            ->filter(fn(Participant $p) => $p->profile?->channel_identifier)
            ->filter(fn(Participant $p) => $p->profile->channel_id === $gcChannelId)
            ->mapWithKeys(fn(Participant $p) => [$p->name => $p->profile])
            ->all();
    }

    private function callLLM(CalendarEvent $event, string $transcript, array $participantMap): ?InsightExtractedDataDTO
    {
        try {
            $prompt = $this->promptBuilder->buildExtractionPrompt(
                transcript:     $transcript,
                participantMap: $participantMap,
                meetingTitle:   $event->title ?? 'Meeting',
                meetingDate:    $event->starts_at ? \Carbon\Carbon::parse($event->starts_at)->toDateString() : now()->toDateString(),
            );

            $json = $this->llm->chat(
                messages:          [new MessageDTO('user', $prompt)],
                model:             Setting::get('model.insight', config('ai.providers.openrouter.models.insight')),
                maxTokens:         4096,
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
     * Persist extracted data. Creates InsightSource + InsightItems + InsightShortTerm per participant.
     *
     * @param  array<string, Profile>  $profileMap  name => Profile
     * @return InsightSource[]
     */
    private function persist(CalendarEvent $event, InsightExtractedDataDTO $data, array $profileMap): array
    {
        // Build identifier => profile_id lookup (channel_identifier => profile)
        $identifierToProfile = collect($profileMap)
            ->keyBy(fn(Profile $p) => $p->channel_identifier)
            ->all();

        $sources = [];

        foreach ($data->participants as $participant) {
            $profile = $identifierToProfile[$participant->identifier] ?? null;

            if (!$profile) {
                Log::info('InsightExtractionService: no profile for participant, skipping', [
                    'identifier' => $participant->identifier,
                    'event_id'   => $event->id,
                ]);
                continue;
            }

            $source = InsightSource::firstOrCreate(
                [
                    'profile_id'  => $profile->id,
                    'source_type' => 'transcript',
                    'source_id'   => $event->id,
                ],
                ['processed_at' => now()],
            );

            foreach ($participant->items as $item) {
                $source->items()->create([
                    'profile_id' => $profile->id,
                    'category'   => $item->category,
                    'fact'       => $item->fact,
                    'confidence' => $item->confidence,
                ]);
            }

            foreach ($participant->shortTerm as $shortTerm) {
                $ttlDays = $shortTerm->contextType === 'emotional_state' ? 7 : 30;

                $source->shortTermMemories()->create([
                    'profile_id'   => $profile->id,
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
