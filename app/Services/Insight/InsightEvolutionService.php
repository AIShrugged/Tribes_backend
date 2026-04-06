<?php

namespace App\Services\Insight;

use App\Domain\DTO\AI\MessageDTO;
use App\Enums\InsightCategory;
use App\Models\AgentActivityLog;
use App\Models\InsightItem;
use App\Models\InsightProfile;
use App\Models\InsightProfileHistory;
use App\Models\InsightSource;
use App\Models\Profile;
use App\Models\Setting;
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
    public function evolveFromSource(InsightSource $source): void
    {
        $newItems = InsightItem::where('profile_id', $source->profile_id)
            ->where('insight_source_id', $source->id)
            ->where('is_archived', false)
            ->get();

        if ($newItems->isEmpty()) {
            return;
        }

        $byCategory = $newItems->groupBy(fn($item) => $item->category->value);
        $user = Profile::find($source->profile_id)?->user;

        foreach ($byCategory as $category => $items) {
            $this->evolveCategory($source->profile_id, $category, $items->pluck('fact')->toArray(), $user);
        }
    }

    /**
     * Evolve a single category profile for a given profile_id.
     *
     * @param  string[]  $newFacts
     */
    public function evolveCategory(int $profileId, string $category, array $newFacts, ?\App\Models\User $user = null): void
    {
        if (empty($newFacts)) {
            return;
        }

        $profile = InsightProfile::firstOrCreate(
            ['profile_id' => $profileId, 'category' => $category],
            ['content' => [], 'version' => 1, 'source_count' => 0],
        );

        $updatedContent = $this->callLLM($profile, $category, $newFacts);

        if ($updatedContent === null) {
            return;
        }

        // Only save history when there is existing content to preserve.
        // Skipping on first creation avoids a useless empty-content history entry.
        if (!$profile->wasRecentlyCreated && !empty($profile->content)) {
            InsightProfileHistory::create([
                'insight_profile_id' => $profile->id,
                'category'           => $profile->category,
                'content'            => $profile->content,
                'version'            => $profile->version,
                'created_at'         => now(),
            ]);
        }

        $profile->update([
            'content'         => $updatedContent,
            'version'         => $profile->version + 1,
            'source_count'    => $profile->source_count + 1,
            'last_updated_at' => now(),
        ]);

        if ($user) {
            AgentActivityLog::recordActivity(
                user: $user,
                toolName: 'insight_evolved',
                toolResult: [
                    'profile_id' => $profileId,
                    'category' => $category,
                    'count' => count($newFacts),
                ],
            );
        }
    }

    /**
     * Full profile rebuild from all items — used by maintenance jobs.
     */
    public function rebuildFromAllItems(int $profileId): void
    {
        $allItems = InsightItem::where('profile_id', $profileId)
            ->where('is_archived', false)
            ->get()
            ->groupBy(fn($item) => $item->category->value);

        foreach ($allItems as $category => $items) {
            $profile = InsightProfile::where('profile_id', $profileId)
                ->where('category', $category)
                ->first();

            if (!$profile) {
                continue;
            }

            $profile->update(['content' => []]);
            $this->evolveCategory($profileId, $category, $items->pluck('fact')->toArray());
        }
    }

    private function callLLM(InsightProfile $insightProfile, string $category, array $newFacts): ?array
    {
        try {
            $prompt = $this->promptBuilder->buildEvolutionPrompt(
                category:        $category,
                existingContent: $insightProfile->content ?? [],
                newFacts:        $newFacts,
            );

            $json = $this->llm->chat(
                messages:          [new MessageDTO('user', $prompt)],
                model:             Setting::get('model.insight', config('ai.providers.openrouter.models.insight')),
                maxTokens:         2048,
                forceJsonResponse: true,
            );

            $data = json_decode($json, true);

            if (!is_array($data)) {
                Log::warning('InsightEvolutionService: invalid JSON from LLM', [
                    'profile_id' => $insightProfile->profile_id,
                    'category'   => $category,
                ]);
                return null;
            }

            return $data;
        } catch (\Throwable $e) {
            Log::error('InsightEvolutionService: LLM call failed', [
                'profile_id' => $insightProfile->profile_id,
                'category'   => $category,
                'error'      => $e->getMessage(),
            ]);
            return null;
        }
    }
}
