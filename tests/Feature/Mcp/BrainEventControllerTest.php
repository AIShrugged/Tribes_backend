<?php

namespace Tests\Feature\Mcp;

use App\Models\BrainEvent;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The second-brain reasoning log endpoints: the sidecar (token with the `mcp`
 * ability) writes its transcript scoped to its org; managers read only their
 * own org's events.
 */
class BrainEventControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
    }

    #[Test]
    public function service_token_records_events_scoped_to_its_organization(): void
    {
        [$user, $org] = $this->managerFor('A');

        Sanctum::actingAs($user, ['mcp']);
        $response = $this->postJson('/api/v1/brain/events', [
            'run_uuid' => 'run-001',
            'events' => [
                ['type' => 'cycle_start', 'payload' => ['subtype' => 'init']],
                ['type' => 'reasoning', 'content' => 'Сверяю решения с задачами'],
                ['type' => 'tool_call', 'tool_name' => 'get_open_issues', 'payload' => ['stale_days' => 7]],
                ['type' => 'cycle_summary', 'content' => 'Готово. NEXT_DELAY_SECONDS=3600'],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertSame(4, BrainEvent::where('organization_id', $org->id)->count());
        $this->assertSame(
            ['cycle_start', 'reasoning', 'tool_call', 'cycle_summary'],
            BrainEvent::where('run_uuid', 'run-001')->orderBy('seq')->pluck('type')->all(),
        );
    }

    #[Test]
    public function a_token_without_the_mcp_ability_is_rejected(): void
    {
        [$user] = $this->managerFor('A');

        Sanctum::actingAs($user, ['something-else']);
        // The `abilities:mcp` middleware rejects; the app masks 403 as 404 for api/*.
        $this->postJson('/api/v1/brain/events', [
            'events' => [['type' => 'reasoning', 'content' => 'x']],
        ])->assertStatus(404);

        $this->assertSame(0, BrainEvent::count());
    }

    #[Test]
    public function a_manager_lists_only_their_own_organizations_events(): void
    {
        [$userA, $orgA] = $this->managerFor('A');
        [, $orgB] = $this->managerFor('B');

        $mine = BrainEvent::create(['organization_id' => $orgA->id, 'run_uuid' => 'a', 'seq' => 0, 'type' => 'reasoning', 'content' => 'mine']);
        BrainEvent::create(['organization_id' => $orgB->id, 'run_uuid' => 'b', 'seq' => 0, 'type' => 'reasoning', 'content' => 'theirs']);

        Sanctum::actingAs($userA, ['*']);
        $response = $this->getJson('/api/v1/brain/events');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($mine->id, $ids);
        $this->assertCount(1, $ids);
    }

    #[Test]
    public function a_non_manager_cannot_read_the_log(): void
    {
        $employee = User::factory()->create();
        $org = Organization::create(['name' => 'Org E', 'slug' => 'org-e-'.uniqid()]);
        $org->users()->attach($employee->id, ['role' => 'employee']);

        Sanctum::actingAs($employee, ['*']);
        $this->getJson('/api/v1/brain/events')->assertStatus(403);
    }

    /** @return array{0: User, 1: Organization} */
    private function managerFor(string $suffix): array
    {
        $user = User::factory()->create();
        $org = Organization::create([
            'name' => "Org {$suffix}",
            'slug' => 'org-'.strtolower($suffix).'-'.uniqid(),
        ]);
        $org->users()->attach($user->id, ['role' => 'manager']);

        return [$user, $org];
    }
}
