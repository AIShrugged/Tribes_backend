<?php

namespace Tests\Feature;

use App\Models\Issue;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IssueProjectCodeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function organization_gets_a_generated_code_on_creation(): void
    {
        $organization = Organization::create(['name' => 'Dev_coding', 'slug' => 'dev-coding']);

        $this->assertSame('DEV', $organization->code);
        $this->assertSame(0, $organization->refresh()->last_issue_number);
    }

    #[Test]
    public function a_second_organization_gets_a_distinct_code_via_letter_variation(): void
    {
        Organization::create(['name' => 'Dev_coding', 'slug' => 'dev-coding']);
        $second = Organization::create(['name' => 'Dev_frontend-code', 'slug' => 'dev-frontend-code']);

        $this->assertSame('DEF', $second->code);
    }

    #[Test]
    public function a_manual_code_override_is_respected_and_uppercased(): void
    {
        $organization = Organization::create(['name' => 'Auchan', 'slug' => 'auchan', 'code' => 'ash']);

        $this->assertSame('ASH', $organization->code);
    }

    #[Test]
    public function issues_get_sequential_codes_per_organization(): void
    {
        $organization = Organization::create(['name' => 'Dev_coding', 'slug' => 'dev-coding']);

        $first = Issue::create(['organization_id' => $organization->id, 'name' => 'A', 'type' => 'development']);
        $second = Issue::create(['organization_id' => $organization->id, 'name' => 'B', 'type' => 'development']);
        $third = Issue::create(['organization_id' => $organization->id, 'name' => 'C', 'type' => 'development']);

        $this->assertSame([1, 'DEV-1'], [$first->number, $first->code]);
        $this->assertSame([2, 'DEV-2'], [$second->number, $second->code]);
        $this->assertSame([3, 'DEV-3'], [$third->number, $third->code]);
        $this->assertSame(3, $organization->refresh()->last_issue_number);
    }

    #[Test]
    public function sequences_are_independent_across_organizations(): void
    {
        $orgA = Organization::create(['name' => 'Dev_coding', 'slug' => 'dev-coding']);
        $orgB = Organization::create(['name' => 'Auchan', 'slug' => 'auchan']);

        $a1 = Issue::create(['organization_id' => $orgA->id, 'name' => 'A', 'type' => 'development']);
        $b1 = Issue::create(['organization_id' => $orgB->id, 'name' => 'B', 'type' => 'organization']);
        $a2 = Issue::create(['organization_id' => $orgA->id, 'name' => 'C', 'type' => 'development']);

        $this->assertSame('DEV-1', $a1->code);
        $this->assertSame('AUC-1', $b1->code);
        $this->assertSame('DEV-2', $a2->code);
    }

    #[Test]
    public function a_team_scoped_issue_uses_the_team_organization_code(): void
    {
        [$organization, $team] = $this->organizationWithTeam('Dev_coding', 'dev-coding');

        $issue = Issue::create(['team_id' => $team->id, 'name' => 'Team task', 'type' => 'development']);

        $this->assertSame('DEV-1', $issue->code);
        $this->assertSame(1, $organization->refresh()->last_issue_number);
    }

    #[Test]
    public function a_personal_issue_without_an_organization_has_no_code(): void
    {
        $user = User::factory()->create();

        $issue = Issue::create(['user_id' => $user->id, 'name' => 'Personal', 'type' => 'development']);

        $this->assertNull($issue->number);
        $this->assertNull($issue->code);
    }

    #[Test]
    public function the_code_is_stable_across_name_updates(): void
    {
        $organization = Organization::create(['name' => 'Dev_coding', 'slug' => 'dev-coding']);

        $organization->update(['name' => 'Renamed org']);

        $this->assertSame('DEV', $organization->refresh()->code);
    }

    private function organizationWithTeam(string $name, string $slug): array
    {
        $methodologyId = Methodology::query()->where('is_default', true)->value('id')
            ?? Methodology::create([
                'name' => 'Default Methodology',
                'text' => 'Default methodology text',
                'scheme' => '{}',
                'is_default' => true,
            ])->id;

        $organization = Organization::create(['name' => $name, 'slug' => $slug]);

        $team = Team::create([
            'organization_id' => $organization->id,
            'name' => 'Team',
            'slug' => $slug.'-team',
            'methodology_id' => $methodologyId,
        ]);

        return [$organization, $team];
    }
}
