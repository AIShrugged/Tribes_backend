<?php

namespace App\Services\Decisions;

use App\Domain\DTO\AI\MessageDTO;
use App\Enums\DecisionSourceType;
use App\Models\CalendarEvent;
use App\Models\Decision;
use App\Models\MeetingSummary;
use App\Models\Setting;
use App\Services\Followup\TranscriptBuilderService;
use App\Services\OpenRouterClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExtractDecisionsService
{
    use ResolvesTeamContexts;

    public function __construct(
        private readonly TranscriptBuilderService $transcriptBuilder,
        private readonly DecisionAuthorResolver $authorResolver,
    ) {}

    public function extract(MeetingSummary $summary): int
    {
        $event = $summary->calendarEvent;
        if (! $event) {
            return 0;
        }

        $rawDecisions = $summary->decisions ?? [];
        if (empty($rawDecisions)) {
            return 0;
        }

        $enriched = $this->enrichWithAuthors($event, $rawDecisions);
        if (empty($enriched)) {
            return 0;
        }

        $teamContexts = $this->resolveTeamContexts($event);

        return DB::transaction(function () use ($event, $summary, $enriched, $teamContexts) {
            Decision::where('summary_id', $summary->id)->delete();

            $created = 0;
            foreach ($enriched as $item) {
                $text = trim($item['text'] ?? '');
                if ($text === '') {
                    continue;
                }

                $resolved = $this->authorResolver->resolve(
                    $event,
                    $item['author_name'] ?? null,
                );

                foreach ($teamContexts as [$teamId, $organizationId]) {
                    Decision::create([
                        'calendar_event_id' => $event->id,
                        'summary_id'        => $summary->id,
                        'team_id'           => $teamId,
                        'organization_id'   => $organizationId,
                        'source_type'       => DecisionSourceType::Meeting->value,
                        'author_user_id'    => $resolved['user_id'],
                        'author_profile_id' => $resolved['profile_id'],
                        'author_raw_name'   => $resolved['raw_name'],
                        'text'              => $text,
                        'topic'             => $item['topic'] ?? null,
                    ]);
                    $created++;
                }
            }

            return $created;
        });
    }

    /**
     * @param  array<int, string>  $decisions
     * @return array<int, array{text: string, author_name: ?string, topic: ?string}>
     */
    private function enrichWithAuthors(CalendarEvent $event, array $decisions): array
    {
        $transcript = $this->transcriptBuilder->build($event);
        if ($transcript === '') {
            return array_map(fn ($d) => [
                'text'        => is_string($d) ? $d : ($d['text'] ?? ''),
                'author_name' => null,
                'topic'       => null,
            ], $decisions);
        }

        $list = [];
        foreach ($decisions as $i => $d) {
            $list[] = sprintf('%d. %s', $i + 1, is_string($d) ? $d : ($d['text'] ?? ''));
        }
        $listStr = implode("\n", $list);

        $prompt = <<<TXT
        Для каждого решения из списка ниже определи по транскрипту:
        - кто его сформулировал/принял (имя участника, как оно звучит в транскрипте) — если явно не видно, верни null
        - короткую тему решения (3-7 слов, на русском)

        Извлекай только содержательные решения, влияющие на работу команды.
        Если решение из списка процедурное (перерыв, переход к следующему пункту, согласие "ок, давай") — отметь его skip=true.

        Верни JSON: {"items": [{"index": 1, "author_name": "Имя или null", "topic": "Тема", "skip": false}]}
        Порядок и количество элементов должны совпадать со списком.

        Список решений:
        {$listStr}

        Транскрипт:
        {$transcript}
        TXT;

        try {
            $json = OpenRouterClient::chat(
                messages: [new MessageDTO('user', $prompt)],
                model: Setting::get('model.meeting_summary', config('ai.providers.openrouter.models.meeting_summary')),
                maxTokens: 2048,
                forceJsonResponse: true,
            );
        } catch (\Throwable $e) {
            Log::warning('ExtractDecisionsService: LLM enrichment failed, falling back to text-only', [
                'event_id' => $event->id,
                'error'    => $e->getMessage(),
            ]);

            return array_map(fn ($d) => [
                'text'        => is_string($d) ? $d : ($d['text'] ?? ''),
                'author_name' => null,
                'topic'       => null,
            ], $decisions);
        }

        if (! preg_match('/\{[\s\S]*\}/s', $json, $matches)) {
            return [];
        }
        $data = json_decode($matches[0], true);
        $items = $data['items'] ?? [];

        $enriched = [];
        foreach ($decisions as $i => $d) {
            $text = is_string($d) ? $d : ($d['text'] ?? '');
            $meta = $items[$i] ?? null;

            if (is_array($meta) && ! empty($meta['skip'])) {
                continue;
            }

            $enriched[] = [
                'text'        => $text,
                'author_name' => is_array($meta) ? ($meta['author_name'] ?? null) : null,
                'topic'       => is_array($meta) ? ($meta['topic'] ?? null) : null,
            ];
        }

        return $enriched;
    }
}