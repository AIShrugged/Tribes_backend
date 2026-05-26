<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\CriticalPathGraph;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\Source;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DashboardOrganizationScopeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function today_briefing_filters_events_by_organization(): void
    {
        $user = User::factory()->create();
        $firstOrganization = $this->organization('first-org');
        $secondOrganization = $this->organization('second-org');
        $firstOrganization->users()->attach($user->id, ['role' => 'employee']);
        $secondOrganization->users()->attach($user->id, ['role' => 'employee']);

        $this->eventFor($user, $firstOrganization, 'First org standup');
        $this->eventFor($user, $secondOrganization, 'Second org standup');

        $this->actingAs($user)
            ->getJson('/api/v1/me/today?organization_id='.$firstOrganization->id)
            ->assertOk()
            ->assertJsonPath('data.events.0.title', 'First org standup')
            ->assertJsonMissing(['title' => 'Second org standup']);
    }

    #[Test]
    public function issue_stats_filter_by_organization(): void
    {
        $user = User::factory()->create();
        $firstOrganization = $this->organization('first-org');
        $secondOrganization = $this->organization('second-org');
        $firstOrganization->users()->attach($user->id, ['role' => 'employee']);
        $secondOrganization->users()->attach($user->id, ['role' => 'employee']);

        Issue::create([
            'name' => 'First org issue',
            'status' => 'open',
            'type' => 'organization',
            'organization_id' => $firstOrganization->id,
            'assignee_id' => $user->id,
        ]);
        Issue::create([
            'name' => 'Second org issue',
            'status' => 'done',
            'type' => 'organization',
            'organization_id' => $secondOrganization->id,
            'assignee_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/issues/stats?organization_id='.$firstOrganization->id)
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.open', 1)
            ->assertJsonPath('data.completed', 0);
    }

    #[Test]
    public function critical_path_rejects_foreign_organization_scope(): void
    {
        $user = User::factory()->create();
        $ownOrganization = $this->organization('own-org');
        $foreignOrganization = $this->organization('foreign-org');
        $ownOrganization->users()->attach($user->id, ['role' => 'employee']);

        CriticalPathGraph::create([
            'organization_id' => $foreignOrganization->id,
            'status' => 'ready',
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/critical-path?organization_id='.$foreignOrganization->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['organization_id']);
    }

    private function organization(string $slug): Organization
    {
        return Organization::create([
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
        ]);
    }

    private function eventFor(User $user, Organization $organization, string $title): CalendarEvent
    {
        $source = Source::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'external_id' => 'source-'.$organization->id,
            'identity' => 'user-'.$organization->id.'@example.test',
            'type' => 'google_calendar',
        ]);

        $event = CalendarEvent::create([
            'title' => $title,
            'url' => 'https://meet.google.com/'.strtolower(str_replace(' ', '-', $title)),
            'starts_at' => Carbon::today()->setHour(10),
            'ends_at' => Carbon::today()->setHour(11),
            'platform' => 'google_meet',
            'description' => '',
        ]);

        $event->sources()->attach($source);

        return $event;
    }
}
