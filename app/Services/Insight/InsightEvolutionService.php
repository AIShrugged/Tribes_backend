<?php

namespace App\Services\Insight;

use App\Domain\DTO\AI\MessageDTO;
use App\Enums\InsightCategory;
use App\Models\InsightItem;
use App\Models\InsightProfile;
use App\Models\InsightProfileHistory;
use App\Models\InsightSource;
use App\Services\OpenRouterClient;
use Illuminate\Support\Facades\Log;

class InsightEvolutionService
{
    public function __construct(
        private readonly OpenRouterClient $llm,
        private readonly InsightPromptBuilder $promptBuilder,
    ) {}

    /**
     * Evolve all long-term profile categories for a participant based on new items from a source.
     */
    public function evolveFromSource(string $email, InsightSource $source): void
    {
        $newItems = InsightItem::where('email', $email)
            ->where('insight_source_id', $source->id)
            ->where('is_archived', false)
            ->get();

        if ($newItems->isEmpty()) {
            return;
        }

        // Group items by category and evolve each one
        $byCategory = $newItems->groupBy(fn($item) => $item->category->value);

        foreach ($byCategory as $category => $items) {
            $this->evolveCategory($email, $category, $items->pluck('fact')->toArray());
        }
    }

    /**
     * Evolve a single category profile for a given email.
     *
     * @param  string[]  $newFacts
     */
    public function evolveCategory(string $email, string $category, array $newFacts): void
    {
        if (empty($newFacts)) {
            return;
        }

        $profile = InsightProfile::firstOrCreate(
            ['email' => $email, 'category' => $category],
            ['content' => [], 'version' => 1, 'source_count' => 0],
        );

        $updatedContent = $this->callLLM($profile, $category, $newFacts);

        if ($updatedContent === null) {
            return;
        }

        // Save history before updating
        InsightProfileHistory::create([
            'insight_profile_id' => $profile->id,
            'email'              => $profile->email,
            'category'           => $profile->category,
            'content'            => $profile->content,
            'version'            => $profile->version,
            'created_at'         => now(),
        ]);

        $profile->update([
            'content'         => $updatedContent,
            'version'         => $profile->version + 1,
            'source_count'    => $profile->source_count + 1,
            'last_updated_at' => now(),
        ]);
    }

    /**
     * Full profile rebuild from all items — used by maintenance jobs.
     */
    public function rebuildFromAllItems(string $email): void
    {
        $allItems = InsightItem::where('email', $email)
            ->where('is_archived', false)
            ->get()
            ->groupBy(fn($item) => $item->category->value);

        foreach ($allItems as $category => $items) {
            $profile = InsightProfile::where('email', $email)
                ->where('category', $category)
                ->first();

            if (!$profile) {
                continue;
            }

            // Reset content and rebuild from scratch
            $profile->update(['content' => []]);
            $this->evolveCategory($email, $category, $items->pluck('fact')->toArray());
        }
    }

    private function callLLM(InsightProfile $profile, string $category, array $newFacts): ?array
    {
        try {
            $prompt = $this->promptBuilder->buildEvolutionPrompt(
                category:        $category,
                existingContent: $profile->content ?? [],
                newFacts:        $newFacts,
            );

            $json = $this->llm->chat(
                messages:         [new MessageDTO('user', $prompt)],
                model:            config('ai.providers.openrouter.models.insight'),
                maxTokens:        2048,
                forceJsonResponse: true,
            );

            $data = json_decode($json, true);

            if (!is_array($data)) {
                Log::warning('InsightEvolutionService: invalid JSON from LLM', [
                    'email'    => $profile->email,
                    'category' => $category,
                ]);
                return null;
            }

            return $data;
        } catch (\Throwable $e) {
            Log::error('InsightEvolutionService: LLM call failed', [
                'email'    => $profile->email,
                'category' => $category,
                'error'    => $e->getMessage(),
            ]);
            return null;
        }
    }
}
