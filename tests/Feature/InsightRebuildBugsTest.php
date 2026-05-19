<?php

namespace Tests\Feature;

use App\Enums\InsightCategory;
use App\Models\InsightItem;
use App\Models\InsightProfile;
use App\Models\InsightProfileHistory;
use App\Models\Profile;
use App\Models\User;
use App\Services\Insight\InsightEvolutionService;
use App\Services\OpenRouterClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression tests for two bugs in InsightEvolutionService::rebuildFromAllItems()
 * surfaced by manual experimentation on the DEV server:
 *
 *   Bug #1 — Empty categories were skipped: if every InsightItem in a category was
 *            archived or migrated, the corresponding InsightProfile retained its
 *            old content forever because rebuild only iterated categories that
 *            still had items.
 *
 *   Bug #2 — Rebuild left no audit trail: InsightProfileHistory was written by
 *            evolveCategory(), but only when the profile already had content.
 *            rebuild clears content BEFORE calling evolveCategory, so the check
 *            always saw an empty profile and skipped history.
 */
class InsightRebuildBugsTest extends TestCase
{
    use RefreshDatabase;

    private Profile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->profile = Profile::create([
            'user_id' => $user->id,
            'channel_id' => 1,
            'channel_identifier' => 'tester@example.com',
            'name' => 'Tester',
        ]);
    }

    /**
     * Mock OpenRouterClient to return a stable, structured response for the
     * category we expect to be rebuilt. Categories with no items must NOT
     * call the LLM at all.
     */
    private function mockLLMOnce(array $response): void
    {
        $mock = Mockery::mock(OpenRouterClient::class);
        $mock->shouldReceive('chat')
            ->andReturn(json_encode($response, JSON_UNESCAPED_UNICODE));
        $this->app->instance(OpenRouterClient::class, $mock);
    }

    #[Test]
    public function rebuild_clears_category_whose_items_were_all_archived(): void
    {
        // Existing strengths profile with content + items.
        $strengths = InsightProfile::create([
            'profile_id' => $this->profile->id,
            'category' => InsightCategory::STRENGTHS->value,
            'content' => [
                'items' => ['Strong DB modeling', 'Architectural thinking'],
                'evidence' => ['User vs Profile primary key discussion'],
            ],
            'version' => 5,
            'source_count' => 4,
        ]);

        InsightItem::create([
            'profile_id' => $this->profile->id,
            'category' => InsightCategory::STRENGTHS->value,
            'fact' => 'Strong DB modeling',
            'confidence' => 0.9,
            'is_archived' => true,        // ← all items archived
        ]);

        $this->mockLLMOnce(['items' => [], 'evidence' => []]);

        app(InsightEvolutionService::class)->rebuildFromAllItems($this->profile->id);

        $strengths->refresh();

        $this->assertSame([], $strengths->content, 'category with no live items must be cleared');
        $this->assertSame(0, $strengths->source_count, 'source_count must reset to 0');
        $this->assertSame(6, $strengths->version, 'version must bump even for empty rebuild');
    }

    #[Test]
    public function rebuild_writes_history_with_pre_rebuild_content(): void
    {
        $strengths = InsightProfile::create([
            'profile_id' => $this->profile->id,
            'category' => InsightCategory::STRENGTHS->value,
            'content' => [
                'items' => ['Old strength X', 'Old strength Y'],
            ],
            'version' => 3,
            'source_count' => 2,
        ]);

        InsightItem::create([
            'profile_id' => $this->profile->id,
            'category' => InsightCategory::STRENGTHS->value,
            'fact' => 'New strength fact',
            'confidence' => 0.85,
            'is_archived' => false,
        ]);

        $this->mockLLMOnce(['items' => ['Brand new strength']]);

        app(InsightEvolutionService::class)->rebuildFromAllItems($this->profile->id);

        $historyCount = InsightProfileHistory::where('insight_profile_id', $strengths->id)->count();
        $this->assertSame(1, $historyCount, 'rebuild must write exactly one history row');

        $hist = InsightProfileHistory::where('insight_profile_id', $strengths->id)->first();
        $this->assertSame(['items' => ['Old strength X', 'Old strength Y']], $hist->content, 'history must capture pre-rebuild content');
        $this->assertSame(3, $hist->version, 'history must capture pre-rebuild version');
    }

    #[Test]
    public function rebuild_skips_history_when_profile_was_already_empty(): void
    {
        $strengths = InsightProfile::create([
            'profile_id' => $this->profile->id,
            'category' => InsightCategory::STRENGTHS->value,
            'content' => [],       // ← already empty
            'version' => 1,
            'source_count' => 0,
        ]);

        InsightItem::create([
            'profile_id' => $this->profile->id,
            'category' => InsightCategory::STRENGTHS->value,
            'fact' => 'New fact',
            'confidence' => 0.85,
            'is_archived' => false,
        ]);

        $this->mockLLMOnce(['items' => ['Fresh strength']]);

        app(InsightEvolutionService::class)->rebuildFromAllItems($this->profile->id);

        $this->assertSame(0, InsightProfileHistory::where('insight_profile_id', $strengths->id)->count(),
            'history must NOT be written for previously-empty content');
    }

    #[Test]
    public function rebuild_iterates_all_profiles_even_when_category_has_no_items(): void
    {
        // Two profiles: strengths has items, development_areas does not.
        $strengths = InsightProfile::create([
            'profile_id' => $this->profile->id,
            'category' => InsightCategory::STRENGTHS->value,
            'content' => ['items' => ['Old strength']],
            'version' => 2,
            'source_count' => 1,
        ]);
        $devAreas = InsightProfile::create([
            'profile_id' => $this->profile->id,
            'category' => InsightCategory::DEVELOPMENT_AREAS->value,
            'content' => ['items' => ['Old weakness']],   // ← stale, must be cleared
            'version' => 4,
            'source_count' => 3,
        ]);

        InsightItem::create([
            'profile_id' => $this->profile->id,
            'category' => InsightCategory::STRENGTHS->value,
            'fact' => 'Some strength',
            'confidence' => 0.9,
            'is_archived' => false,
        ]);
        // No items at all for DEVELOPMENT_AREAS.

        $this->mockLLMOnce(['items' => ['Rebuilt strength']]);

        app(InsightEvolutionService::class)->rebuildFromAllItems($this->profile->id);

        $devAreas->refresh();
        $strengths->refresh();

        $this->assertSame([], $devAreas->content, 'development_areas with no items must be cleared');
        $this->assertSame(5, $devAreas->version, 'devAreas version bumped from 4 → 5');
        $this->assertSame(0, $devAreas->source_count);

        $this->assertNotSame([], $strengths->content, 'strengths must be regenerated, not empty');
    }
}
