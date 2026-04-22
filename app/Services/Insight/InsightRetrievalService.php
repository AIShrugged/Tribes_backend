<?php

namespace App\Services\Insight;

use App\Domain\DTO\AI\MessageDTO;
use App\Models\AgentActivityLog;
use App\Models\InsightProfile;
use App\Models\InsightRelationship;
use App\Models\InsightShortTerm;
use App\Models\Profile;
use App\Models\Setting;
use App\Services\OpenRouterClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class InsightRetrievalService
{
    public function __construct(
        private readonly OpenRouterClient $llm,
        private readonly InsightPromptBuilder $promptBuilder,
    ) {}

    /**
     * Get formatted memory context for injecting into a Wanda Bot prompt.
     */
    public function getContextForQuery(int $profileId, string $query): string
    {
        $availableCategories = InsightProfile::where('profile_id', $profileId)
            ->where('source_count', '>=', 3)
            ->pluck('category')
            ->map(fn($cat) => $cat->value)
            ->toArray();

        if (empty($availableCategories)) {
            return '';
        }

        $relevantCategories = $this->selectRelevantCategories($query, $availableCategories);

        if (empty($relevantCategories)) {
            return '';
        }

        $context = $this->formatContext(
            $profiles = InsightProfile::where('profile_id', $profileId)
                ->whereIn('category', $relevantCategories)
                ->get()
                ->keyBy(fn($p) => $p->category->value),
            $shortTerm = InsightShortTerm::where('profile_id', $profileId)
                ->active()
                ->get()
        );

        if ($context !== '') {
            $user = Profile::find($profileId)?->user;
            if ($user) {
                AgentActivityLog::recordActivity(
                    user: $user,
                    toolName: 'insight_context_selected',
                    toolResult: [
                        'count' => count($relevantCategories),
                        'profile_id' => $profileId,
                    ],
                    toolArgs: [
                        'query_length' => mb_strlen($query),
                    ],
                );
            }
        }

        return $context;
    }

    /**
     * Get the full structured profile for a profile_id.
     */
    public function getFullProfile(int $profileId): array
    {
        $profiles = InsightProfile::where('profile_id', $profileId)
            ->get()
            ->keyBy(fn($p) => $p->category->value);

        $shortTerm = InsightShortTerm::where('profile_id', $profileId)
            ->active()
            ->orderByDesc('created_at')
            ->get()
            ->groupBy(fn($s) => $s->context_type->value);

        $relationships = InsightRelationship::where('profile_id_a', $profileId)
            ->orWhere('profile_id_b', $profileId)
            ->get();

        return [
            'profile_id'    => $profileId,
            'is_ready'      => $profiles->filter(fn($p) => $p->isReady())->isNotEmpty(),
            'profiles'      => $profiles->map(fn($p) => [
                'category'     => $p->category->value,
                'content'      => $p->content,
                'version'      => $p->version,
                'source_count' => $p->source_count,
                'last_updated' => $p->last_updated_at?->toDateString(),
            ])->values(),
            'short_term'    => $shortTerm->map(fn($items) => $items->map(fn($s) => [
                'context_type' => $s->context_type->value,
                'content'      => $s->content,
                'expires_at'   => $s->expires_at->toDateString(),
            ])->first())->values(),
            'relationships' => $relationships->map(fn($r) => [
                'with'              => $r->profile_id_a === $profileId ? $r->profile_id_b : $r->profile_id_a,
                'type'              => $r->relationship_type->value,
                'dynamics'          => $r->dynamics,
                'interaction_count' => $r->interaction_count,
            ])->values(),
        ];
    }

    /**
     * Get short-term context for a profile_id.
     */
    public function getShortTermContext(int $profileId): array
    {
        return InsightShortTerm::where('profile_id', $profileId)
            ->active()
            ->orderByDesc('created_at')
            ->get()
            ->groupBy(fn($s) => $s->context_type->value)
            ->map(fn($items) => $items->first()->content)
            ->toArray();
    }

    /**
     * Get relationship between two profiles.
     */
    public function getRelationship(int $profileIdA, int $profileIdB): ?array
    {
        $rel = InsightRelationship::findPair($profileIdA, $profileIdB);

        if (!$rel) {
            return null;
        }

        return [
            'profile_id_a'      => $rel->profile_id_a,
            'profile_id_b'      => $rel->profile_id_b,
            'type'              => $rel->relationship_type->value,
            'dynamics'          => $rel->dynamics,
            'interaction_count' => $rel->interaction_count,
            'last_interaction'  => $rel->last_interaction_at?->toDateString(),
        ];
    }

    private function selectRelevantCategories(string $query, array $available): array
    {
        try {
            $prompt = $this->promptBuilder->buildCategorySelectionPrompt($query, $available);

            $json = $this->llm->chat(
                messages:          [new MessageDTO('user', $prompt)],
                model:             Setting::get('model.insight', config('ai.providers.anthropic.models.insight')),
                maxTokens:         256,
                forceJsonResponse: true,
            );

            $data = json_decode($json, true);

            if (!is_array($data)) {
                return $available;
            }

            return array_intersect($data, $available);
        } catch (\Throwable $e) {
            Log::warning('InsightRetrievalService: category selection failed, using all', ['error' => $e->getMessage()]);
            return $available;
        }
    }

    private function formatContext(Collection $profiles, Collection $shortTerm): string
    {
        if ($profiles->isEmpty() && $shortTerm->isEmpty()) {
            return '';
        }

        $lines = ['=== USER INSIGHT CONTEXT ==='];

        foreach ($profiles as $category => $profile) {
            if (empty($profile->content)) {
                continue;
            }
            $lines[] = "\n[{$category}]";
            $lines[] = json_encode($profile->content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        if ($shortTerm->isNotEmpty()) {
            $lines[] = "\n[current_state]";
            $grouped = $shortTerm->groupBy(fn($s) => $s->context_type->value);
            foreach ($grouped as $type => $items) {
                $lines[] = "{$type}: " . json_encode($items->first()->content, JSON_UNESCAPED_UNICODE);
            }
        }

        $lines[] = '=== END CONTEXT ===';

        return implode("\n", $lines);
    }
}
