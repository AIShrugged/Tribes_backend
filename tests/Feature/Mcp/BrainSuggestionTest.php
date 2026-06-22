<?php

namespace Tests\Feature\Mcp;

use App\Models\BrainSuggestion;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\User;
use App\Services\Agent\Tools\SuggestActionTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The human-in-the-loop suggestion flow: the brain proposes via suggest_action
 * (no direct mutation); a manager approves → the backend applies deterministically.
 */
class BrainSuggestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        Queue::fake(); // keep the issue auto-pipeline from running in tests
    }

    #[Test]
    public function suggest_action_creates_a_pending_suggestion_scoped_to_org(): void
    {
        [$user, $org] = $this->managerFor('A');
        $this->actingAs($user);

        $res = (new SuggestActionTool())->execute([
            'key' => 'create_issue',
            'title' => '[BRAIN] Add missing task',
            'dedupe_key' => 'lost:decision:1:x',
            'reasoning' => 'Decided but never tracked',
            'payload' => ['name' => '[BRAIN] Add missing task', 'type' => 'organization'],
        ]);

        $this->assertTrue($res['success']);
        $this->assertSame(1, BrainSuggestion::where('organization_id', $org->id)->pending()->count());
    }

    #[Test]
    public function suggest_action_is_idempotent_by_dedupe_key(): void
    {
        [$user] = $this->managerFor('A');
        $this->actingAs($user);
        $tool = new SuggestActionTool();

        $tool->execute(['key' => 'create_issue', 'title' => 'v1', 'dedupe_key' => 'k1', 'payload' => ['name' => 'v1', 'type' => 'organization']]);
        $tool->execute(['key' => 'create_issue', 'title' => 'v2', 'dedupe_key' => 'k1', 'payload' => ['name' => 'v2', 'type' => 'organization']]);

        $this->assertSame(1, BrainSuggestion::where('dedupe_key', 'k1')->count());
        $this->assertSame('v2', BrainSuggestion::where('dedupe_key', 'k1')->first()->title);
    }

    #[Test]
    public function approving_a_create_issue_suggestion_creates_the_issue(): void
    {
        [$user, $org] = $this->managerFor('A');
        $suggestion = $this->suggestion($org, 'create_issue', ['name' => '[BRAIN] Fix dashboard counts', 'type' => 'organization']);

        Sanctum::actingAs($user, ['*']);
        $response = $this->postJson("/api/v1/brain/suggestions/{$suggestion->id}/approve");

        $response->assertOk();
        $suggestion->refresh();
        $this->assertSame(BrainSuggestion::STATUS_APPLIED, $suggestion->status);
        $issueId = $suggestion->applied_result['issue_id'] ?? null;
        $this->assertNotNull($issueId);
        $this->assertDatabaseHas('issues', ['id' => $issueId, 'organization_id' => $org->id, 'name' => '[BRAIN] Fix dashboard counts']);
    }

    #[Test]
    public function approving_an_invalid_suggestion_fails_safe_without_creating_anything(): void
    {
        [$user, $org] = $this->managerFor('A');
        $suggestion = $this->suggestion($org, 'create_issue', ['name' => 'x', 'type' => 'NOT_A_REAL_TYPE']);

        Sanctum::actingAs($user, ['*']);
        $this->postJson("/api/v1/brain/suggestions/{$suggestion->id}/approve")->assertStatus(422);

        $this->assertSame(BrainSuggestion::STATUS_FAILED, $suggestion->fresh()->status);
        $this->assertSame(0, Issue::count());
    }

    #[Test]
    public function approving_an_update_task_status_suggestion_changes_the_issue(): void
    {
        [$user, $org] = $this->managerFor('A');
        $issue = Issue::create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'Stalled task',
            'type' => Issue::TYPE_ORGANIZATION,
            'status' => 'open',
        ]);
        $suggestion = $this->suggestion($org, 'update_task_status', ['issue_id' => $issue->id, 'status' => 'done']);

        Sanctum::actingAs($user, ['*']);
        $this->postJson("/api/v1/brain/suggestions/{$suggestion->id}/approve")->assertOk();

        $this->assertSame('done', $issue->fresh()->status);
    }

    #[Test]
    public function a_manager_cannot_approve_another_organizations_suggestion(): void
    {
        [$userA] = $this->managerFor('A');
        [, $orgB] = $this->managerFor('B');
        $suggestion = $this->suggestion($orgB, 'create_issue', ['name' => 'x', 'type' => 'organization']);

        Sanctum::actingAs($userA, ['*']);
        $this->postJson("/api/v1/brain/suggestions/{$suggestion->id}/approve")->assertStatus(403);
        $this->assertSame(BrainSuggestion::STATUS_PENDING, $suggestion->fresh()->status);
    }

    #[Test]
    public function a_rejected_suggestion_is_not_resurrected_by_re_proposing(): void
    {
        [$user, $org] = $this->managerFor('A');
        $suggestion = $this->suggestion($org, 'create_issue', ['name' => 'x', 'type' => 'organization'], 'dup-key');

        Sanctum::actingAs($user, ['*']);
        $this->postJson("/api/v1/brain/suggestions/{$suggestion->id}/reject")->assertOk();

        // Brain re-proposes the same dedupe_key.
        $this->actingAs($user);
        $res = (new SuggestActionTool())->execute(['key' => 'create_issue', 'title' => 'again', 'dedupe_key' => 'dup-key', 'payload' => ['name' => 'x', 'type' => 'organization']]);

        $this->assertTrue($res['success']);
        $this->assertSame('rejected', $res['status']);
        $this->assertSame(0, BrainSuggestion::where('organization_id', $org->id)->pending()->count());
    }

    private function suggestion(Organization $org, string $key, array $payload, string $dedupe = 'k'): BrainSuggestion
    {
        return BrainSuggestion::create([
            'organization_id' => $org->id,
            'key' => $key,
            'payload_version' => 1,
            'payload' => $payload,
            'title' => 'proposal',
            'dedupe_key' => $dedupe,
            'status' => BrainSuggestion::STATUS_PENDING,
        ]);
    }

    /** @return array{0: User, 1: Organization} */
    private function managerFor(string $suffix): array
    {
        $user = User::factory()->create();
        $org = Organization::create(['name' => "Org {$suffix}", 'slug' => 'org-'.strtolower($suffix).'-'.uniqid()]);
        $org->users()->attach($user->id, ['role' => 'manager']);

        return [$user, $org];
    }
}
