<?php

namespace App\Services\Insight;

use App\Domain\DTO\AI\MessageDTO;
use App\Enums\InsightCategory;
use App\Models\InsightProfile;
use App\Models\InsightRelationship;
use App\Models\InsightShortTerm;
use App\Services\OpenRouterClient;
use Illuminate\Support\Facades\Log;

class InsightRetrievalService
{
    private const MAX_CONTEXT_TOKENS = 2000;

    public function __construct(
        private readonly OpenRouterClient $llm,
        private readonly InsightPromptBuilder $promptBuilder,
    ) {}

    /**
     * Get formatted memory context for injecting into a Wanda Bot prompt.
     * Tiered: categories → summaries → drill down if needed.
     */
    public function getContextForQuery(string $email, string $query): string
    {
        $availableCategories = InsightProfile::where('email', $email)
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

        $profiles = InsightProfile::where('email', $email)
            ->whereIn('category', $relevantCategories)
            ->get()
            ->keyBy(fn($p) => $p->category->value);

        $shortTerm = InsightShortTerm::where('email', $email)
            ->active()
            ->get();

        return $this->formatContext($profiles, $shortTerm);
    }

    /**
     * Get the full profile for an email (all ready categories).
     */
    public function getFullProfile(string $email): array
    {
        $profiles = InsightProfile::where('email', $email)
            ->get()
            ->keyBy(fn($p) => $p->category->value);

        $shortTerm = InsightShortTerm::where('email', $email)
            ->active()
            ->get()
            ->groupBy(fn($s) => $s->context_type->value);

        $relationships = InsightRelationship::where('email_a', $email)
            ->orWhere('email_b', $email)
            ->get();

        return [
            'email'         => $email,
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
                'with'             => $r->email_a === $email ? $r->email_b : $r->email_a,
                'type'             => $r->relationship_type->value,
                'dynamics'         => $r->dynamics,
                'interaction_count' => $r->interaction_count,
            ])->values(),
        ];
    }

    /**
     * Get short-term context (current state) for an email.
     */
    public function getShortTermContext(string $email): array
    {
        return InsightShortTerm::where('email', $email)
            ->active()
            ->orderByDesc('created_at')
            ->get()
            ->groupBy(fn($s) => $s->context_type->value)
            ->map(fn($items) => $items->first()->content)
            ->toArray();
    }

    /**
     * Get relationship between two people.
     */
    public function getRelationship(string $emailA, string $emailB): ?array
    {
        $rel = InsightRelationship::findPair($emailA, $emailB);

        if (!$rel) {
            return null;
        }

        return [
            'email_a'           => $rel->email_a,
            'email_b'           => $rel->email_b,
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
                messages:         [new MessageDTO('user', $prompt)],
                model:            config('ai.providers.openrouter.models.insight'),
                maxTokens:        256,
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

    private function formatContext($profiles, $shortTerm): string
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
