<?php

namespace App\Services\Meeting;

use App\Domain\DTO\AI\MessageDTO;
use App\Enums\FollowupStatus;
use App\Models\CalendarEvent;
use App\Models\MeetingSummary;
use App\Services\Followup\TranscriptBuilderService;
use App\Services\OpenRouterClient;
use Illuminate\Support\Facades\Log;

class MeetingSummaryService
{
    public function __construct(
        private readonly OpenRouterClient $llm,
        private readonly TranscriptBuilderService $transcriptBuilder,
    ) {
    }

    public function generate(CalendarEvent $event): MeetingSummary
    {
        $summary = $event->meetingSummary()->updateOrCreate([], [
            'status'     => FollowupStatus::IN_PROGRESS->value,
            'title'      => null,
            'summary'    => null,
            'key_points' => null,
            'decisions'  => null,
        ]);

        try {
            $transcript = $this->transcriptBuilder->build($event);

            $json = $this->llm->chat(
                messages: [new MessageDTO('user', $this->buildPrompt($transcript))],
                model: config('ai.providers.openrouter.models.meeting_summary'),
                maxTokens: 4096,
                forceJsonResponse: true,
            );

            $data = json_decode($json, true);

            $summary->update([
                'status'     => FollowupStatus::DONE->value,
                'title'      => $data['title'] ?? null,
                'summary'    => $data['summary'] ?? null,
                'key_points' => $data['key_points'] ?? [],
                'decisions'  => $data['decisions'] ?? [],
            ]);
        } catch (\Throwable $e) {
            Log::error('MeetingSummaryService: generation failed', ['error' => $e->getMessage()]);
            $summary->update(['status' => FollowupStatus::FAILED->value]);
        }

        return $summary->fresh();
    }

    private function buildPrompt(string $transcript): string
    {
        return <<<TXT
        Проанализируй транскрипт встречи и верни JSON строго в следующем формате:
        {
            "title": "Краткое название встречи (до 10 слов)",
            "summary": "2-3 предложения с кратким изложением сути встречи",
            "key_points": ["Ключевой момент 1", "Ключевой момент 2"],
            "decisions": ["Принятое решение 1"]
        }

        Если решений не было — верни пустой массив для decisions.
        Отвечай только валидным JSON без дополнительного текста.

        Транскрипт встречи:
        {$transcript}
        TXT;
    }
}
