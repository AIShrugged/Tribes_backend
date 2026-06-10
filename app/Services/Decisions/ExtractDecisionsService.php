<?php

namespace App\Services\Decisions;

use App\Domain\DTO\AI\MessageDTO;
use App\Enums\DecisionSourceType;
use App\Models\CalendarEvent;
use App\Models\Decision;
use App\Models\MeetingSummary;
use App\Models\Setting;
use App\Services\Followup\TranscriptBuilderService;
use App\Services\LlmPromptService;
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
        // Thin wrapper over the compute/apply seam. Behavior is identical to the pre-split method:
        // the transaction (delete-then-insert) runs iff there is >=1 non-skip decision, exactly as
        // before — skipped/empty decisions never reach the DB. The seam lets the pre-moderation gate
        // stage the M enriched items (skip editable) and replay applyDecisionPlan() on approve.
        $plan = $this->computeDecisionPlan($summary);

        $hasApplicable = collect($plan['items'] ?? [])->contains(fn (array $item) => empty($item['skip']));
        if (! $hasApplicable) {
            return 0;
        }

        return $this->applyDecisionPlan($summary, $plan['items']);
    }

    /**
     * COMPUTE half: enrich raw summary decisions with authors/topics (LLM read, NO DB write) and
     * return ALL of them — including LLM-flagged `skip` items — each with a stable uid so the
     * moderation UI can show and un-skip them. The skip FILTER lives in applyDecisionPlan, not here.
     *
     * @return array{items: array<int, array{uid: string, text: string, author_name: ?string, topic: ?string, skip: bool}>}
     */
    public function computeDecisionPlan(MeetingSummary $summary): array
    {
        $event = $summary->calendarEvent;
        if (! $event) {
            return ['items' => []];
        }

        $rawDecisions = $summary->decisions ?? [];
        if (empty($rawDecisions)) {
            return ['items' => []];
        }

        $enriched = $this->enrichWithAuthors($event, $rawDecisions);
        if (empty($enriched)) {
            return ['items' => []];
        }

        $items = [];
        foreach (array_values($enriched) as $i => $e) {
            $items[] = [
                'uid'         => 'd-'.$i,
                'text'        => $e['text'] ?? '',
                'author_name' => $e['author_name'] ?? null,
                'topic'       => $e['topic'] ?? null,
                'skip'        => (bool) ($e['skip'] ?? false),
            ];
        }

        return ['items' => $items];
    }

    /**
     * APPLY half: persist the (possibly edited) enriched items, fanning out one Decision row per
     * team context. Idempotent via delete-then-insert. Skip items are dropped HERE (relocated from
     * enrichWithAuthors) so they remain visible/editable in the staged plan.
     *
     * @param  array<int, array>  $editedItems
     */
    public function applyDecisionPlan(MeetingSummary $summary, array $editedItems): int
    {
        $event = $summary->calendarEvent;
        if (! $event) {
            return 0;
        }

        $teamContexts = $this->resolveTeamContexts($event);

        return DB::transaction(function () use ($event, $summary, $editedItems, $teamContexts) {
            Decision::where('summary_id', $summary->id)->delete();

            $created = 0;
            foreach ($editedItems as $item) {
                if (! empty($item['skip'])) {
                    continue;
                }

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
                'skip'        => false,
            ], $decisions);
        }

        $list = [];
        foreach ($decisions as $i => $d) {
            $list[] = sprintf('%d. %s', $i + 1, is_string($d) ? $d : ($d['text'] ?? ''));
        }
        $listStr = implode("\n", $list);

        $prompt = app(LlmPromptService::class)->renderView(
            slug: 'decisions.extract.authors.user',
            organizationId: $event->source?->organization_id,
            fallbackView: 'llm-prompts.decisions.extract-authors-user',
            variables: [
                'decisions' => $listStr,
                'transcript' => $transcript,
            ],
            name: 'Decision author extraction prompt',
        );

        try {
            $json = app(OpenRouterClient::class)->chat(
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
                'skip'        => false,
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

            // NOTE: skip items are NO LONGER dropped here — they are carried with a `skip` flag so
            // the moderation UI can surface and un-skip them. The drop now happens in
            // applyDecisionPlan(), keeping non-gated (extract()) behavior byte-for-byte identical.
            $enriched[] = [
                'text'        => $text,
                'author_name' => is_array($meta) ? ($meta['author_name'] ?? null) : null,
                'topic'       => is_array($meta) ? ($meta['topic'] ?? null) : null,
                'skip'        => is_array($meta) ? (bool) ($meta['skip'] ?? false) : false,
            ];
        }

        return $enriched;
    }
}
