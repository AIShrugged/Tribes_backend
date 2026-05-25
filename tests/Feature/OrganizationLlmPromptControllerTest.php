<?php

namespace Tests\Feature;

use App\Models\LlmPrompt;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrganizationLlmPromptControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function manager_can_list_and_update_organization_prompts(): void
    {
        [$manager, $organization] = $this->makeMember('manager');
        $prompt = $organization->llmPrompts()->where('slug', 'meeting.summary.user')->firstOrFail();

        $this->actingAs($manager)
            ->getJson("/api/v1/organizations/{$organization->id}/llm-prompts?search=summary")
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'meeting.summary.user');

        $this->actingAs($manager)
            ->patchJson("/api/v1/organizations/{$organization->id}/llm-prompts/{$prompt->id}", [
                'name' => 'Custom summary',
                'prompt' => 'Custom prompt with {transcript}',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Custom summary')
            ->assertJsonPath('data.prompt', 'Custom prompt with {transcript}');

        $this->assertDatabaseHas('llm_prompts', [
            'id' => $prompt->id,
            'name' => 'Custom summary',
            'prompt' => 'Custom prompt with {transcript}',
        ]);
    }

    #[Test]
    public function employee_can_view_but_cannot_update_organization_prompts(): void
    {
        [$employee, $organization] = $this->makeMember('employee');
        $prompt = $organization->llmPrompts()->where('slug', 'meeting.summary.user')->firstOrFail();

        $this->actingAs($employee)
            ->getJson("/api/v1/organizations/{$organization->id}/llm-prompts")
            ->assertOk();

        $this->actingAs($employee)
            ->patchJson("/api/v1/organizations/{$organization->id}/llm-prompts/{$prompt->id}", [
                'prompt' => 'Nope',
            ])
            ->assertForbidden();
    }

    #[Test]
    public function reset_restores_default_prompt_text(): void
    {
        [$manager, $organization] = $this->makeMember('manager');
        $prompt = $organization->llmPrompts()->where('slug', 'today.daily_nudge.user')->firstOrFail();
        $prompt->update(['name' => 'Changed', 'prompt' => 'Changed text']);

        $this->actingAs($manager)
            ->postJson("/api/v1/organizations/{$organization->id}/llm-prompts/{$prompt->id}/reset")
            ->assertOk()
            ->assertJsonPath('data.name', 'Daily nudge prompt')
            ->assertJsonFragment(['slug' => 'today.daily_nudge.user']);

        $this->assertStringContainsString(
            '{data}',
            (string) $prompt->fresh()->prompt,
        );
    }

    #[Test]
    public function seed_creates_missing_prompts(): void
    {
        [$manager, $organization] = $this->makeMember('manager');
        LlmPrompt::query()->where('organization_id', $organization->id)->delete();

        $this->actingAs($manager)
            ->postJson("/api/v1/organizations/{$organization->id}/llm-prompts/seed")
            ->assertOk()
            ->assertJsonPath('data.created', 31)
            ->assertJsonPath('data.updated', 0)
            ->assertJsonPath('data.skipped', 0);

        $this->assertSame(31, LlmPrompt::query()->where('organization_id', $organization->id)->count());
    }

    #[Test]
    public function prompt_from_another_organization_returns_not_found(): void
    {
        [$manager, $organization] = $this->makeMember('manager');
        [, $otherOrganization] = $this->makeMember('manager', 'other-org');
        $foreignPrompt = $otherOrganization->llmPrompts()->firstOrFail();

        $this->actingAs($manager)
            ->getJson("/api/v1/organizations/{$organization->id}/llm-prompts/{$foreignPrompt->id}")
            ->assertNotFound();
    }

    private function makeMember(string $role, string $slug = 'acme'): array
    {
        $user = User::factory()->create();
        $organization = Organization::create([
            'name' => 'Acme '.$slug,
            'slug' => $slug.'-'.uniqid(),
        ]);
        $organization->users()->attach($user->id, ['role' => $role]);

        return [$user, $organization];
    }
}
