<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Team;
use App\Models\TeamNotificationSetting;
use App\Models\TelegramChatRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TelegramNotificationSettingApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Organization, 1: Team, 2: User}
     */
    private function context(string $role = 'manager'): array
    {
        $user = User::factory()->create();
        $organization = Organization::create(['name' => 'Notif Org', 'slug' => 'notif-org-'.uniqid()]);
        $defaultTeam = $organization->defaultTeam
            ?? Team::create([
                'organization_id' => $organization->id,
                'name' => 'General',
                'slug' => 'general-'.$organization->id,
                'is_default' => true,
            ]);
        $organization->users()->attach($user->id, ['role' => $role]);

        return [$organization, $defaultTeam, $user];
    }

    private function boundChat(Organization $org, Team $team, int $chatId): TelegramChatRegistration
    {
        return TelegramChatRegistration::create([
            'telegram_chat_id' => $chatId,
            'chat_type' => 'group',
            'chat_title' => 'Chat '.$chatId,
            'organization_id' => $org->id,
            'team_id' => $team->id,
            'bound_at' => now(),
        ]);
    }

    #[Test]
    public function sync_creates_one_row_per_chat_for_an_event(): void
    {
        [$org, $team, $manager] = $this->context();
        $a = $this->boundChat($org, $team, 600001);
        $b = $this->boundChat($org, $team, 600002);

        $this->actingAs($manager)
            ->putJson("/api/v1/teams/{$team->id}/notification-settings/sync", [
                'event_type' => 'meeting_summary',
                'channel_type' => 'telegram',
                'chat_ids' => [$a->id, $b->id],
            ])
            ->assertStatus(200);

        $this->assertSame(2, TeamNotificationSetting::where('team_id', $team->id)
            ->where('event_type', 'meeting_summary')->count());
    }

    #[Test]
    public function sync_is_idempotent_and_removes_unselected_preserving_enabled(): void
    {
        [$org, $team, $manager] = $this->context();
        $a = $this->boundChat($org, $team, 600011);
        $b = $this->boundChat($org, $team, 600012);

        $sync = fn (array $ids) => $this->actingAs($manager)->putJson(
            "/api/v1/teams/{$team->id}/notification-settings/sync",
            ['event_type' => 'meeting_summary', 'channel_type' => 'telegram', 'chat_ids' => $ids],
        );

        $sync([$a->id, $b->id])->assertStatus(200);

        // Disable chat A via the master toggle's per-row state, then re-sync the same set.
        TeamNotificationSetting::where('team_id', $team->id)
            ->where('event_type', 'meeting_summary')
            ->where('notifiable_id', $a->id)
            ->update(['enabled' => false]);

        $sync([$a->id, $b->id])->assertStatus(200); // idempotent
        $this->assertSame(2, TeamNotificationSetting::where('team_id', $team->id)
            ->where('event_type', 'meeting_summary')->count());
        $this->assertFalse((bool) TeamNotificationSetting::where('team_id', $team->id)
            ->where('event_type', 'meeting_summary')->where('notifiable_id', $a->id)->value('enabled'));

        // Drop B.
        $sync([$a->id])->assertStatus(200);
        $rows = TeamNotificationSetting::where('team_id', $team->id)->where('event_type', 'meeting_summary')->get();
        $this->assertCount(1, $rows);
        $this->assertSame($a->id, (int) $rows->first()->notifiable_id);
        $this->assertFalse((bool) $rows->first()->enabled); // preserved across re-sync
    }

    #[Test]
    public function sync_with_empty_array_clears_all_recipients(): void
    {
        [$org, $team, $manager] = $this->context();
        $a = $this->boundChat($org, $team, 600021);

        $this->actingAs($manager)->putJson("/api/v1/teams/{$team->id}/notification-settings/sync", [
            'event_type' => 'meeting_summary', 'channel_type' => 'telegram', 'chat_ids' => [$a->id],
        ])->assertStatus(200);

        $this->actingAs($manager)->putJson("/api/v1/teams/{$team->id}/notification-settings/sync", [
            'event_type' => 'meeting_summary', 'channel_type' => 'telegram', 'chat_ids' => [],
        ])->assertStatus(200);

        $this->assertSame(0, TeamNotificationSetting::where('team_id', $team->id)
            ->where('event_type', 'meeting_summary')->count());
    }

    #[Test]
    public function sync_rejects_unbound_chat_and_rolls_back(): void
    {
        [$org, $team, $manager] = $this->context();
        $bound = $this->boundChat($org, $team, 600031);
        $unbound = TelegramChatRegistration::create([
            'telegram_chat_id' => 600032, 'chat_type' => 'group',
            'organization_id' => $org->id, 'team_id' => $team->id, 'bound_at' => null,
        ]);

        $this->actingAs($manager)->putJson("/api/v1/teams/{$team->id}/notification-settings/sync", [
            'event_type' => 'meeting_summary', 'channel_type' => 'telegram',
            'chat_ids' => [$bound->id, $unbound->id],
        ])->assertStatus(422);

        // Whole transaction rolled back — no partial rows.
        $this->assertSame(0, TeamNotificationSetting::where('team_id', $team->id)
            ->where('event_type', 'meeting_summary')->count());
    }

    #[Test]
    public function sync_rejects_chat_from_another_organization(): void
    {
        [$org, $team, $manager] = $this->context();
        $otherOrg = Organization::create(['name' => 'Other', 'slug' => 'other-'.uniqid()]);
        $otherDefault = $otherOrg->defaultTeam
            ?? Team::create(['organization_id' => $otherOrg->id, 'name' => 'General', 'slug' => 'g-'.$otherOrg->id, 'is_default' => true]);
        $foreignChat = $this->boundChat($otherOrg, $otherDefault, 600041);

        $this->actingAs($manager)->putJson("/api/v1/teams/{$team->id}/notification-settings/sync", [
            'event_type' => 'meeting_summary', 'channel_type' => 'telegram', 'chat_ids' => [$foreignChat->id],
        ])->assertStatus(422);
    }

    #[Test]
    public function sync_rejects_dead_meeting_tasks_event_type(): void
    {
        [$org, $team, $manager] = $this->context();
        $a = $this->boundChat($org, $team, 600051);

        $this->actingAs($manager)->putJson("/api/v1/teams/{$team->id}/notification-settings/sync", [
            'event_type' => 'meeting_tasks', 'channel_type' => 'telegram', 'chat_ids' => [$a->id],
        ])->assertStatus(422);
    }

    #[Test]
    public function set_enabled_flips_every_row_for_an_event(): void
    {
        [$org, $team, $manager] = $this->context();
        $a = $this->boundChat($org, $team, 600061);
        $b = $this->boundChat($org, $team, 600062);

        $this->actingAs($manager)->putJson("/api/v1/teams/{$team->id}/notification-settings/sync", [
            'event_type' => 'meeting_summary', 'channel_type' => 'telegram', 'chat_ids' => [$a->id, $b->id],
        ])->assertStatus(200);

        $this->actingAs($manager)->putJson("/api/v1/teams/{$team->id}/notification-settings/set-enabled", [
            'event_type' => 'meeting_summary', 'channel_type' => 'telegram', 'enabled' => false,
        ])->assertStatus(200);

        $this->assertSame(0, TeamNotificationSetting::where('team_id', $team->id)
            ->where('event_type', 'meeting_summary')->where('enabled', true)->count());
    }

    #[Test]
    public function set_minutes_before_updates_all_rows(): void
    {
        [$org, $team, $manager] = $this->context();
        $a = $this->boundChat($org, $team, 600071);

        $this->actingAs($manager)->putJson("/api/v1/teams/{$team->id}/notification-settings/sync", [
            'event_type' => 'meeting_agenda', 'channel_type' => 'telegram', 'chat_ids' => [$a->id],
        ])->assertStatus(200);

        $this->actingAs($manager)->putJson("/api/v1/teams/{$team->id}/notification-settings/set-minutes-before", [
            'event_type' => 'meeting_agenda', 'channel_type' => 'telegram', 'minutes_before' => 120,
        ])->assertStatus(200);

        $this->assertSame(120, (int) TeamNotificationSetting::where('team_id', $team->id)
            ->where('event_type', 'meeting_agenda')->where('notifiable_id', $a->id)->value('minutes_before'));
    }

    #[Test]
    public function set_minutes_before_null_clears_without_error(): void
    {
        [$org, $team, $manager] = $this->context();
        $a = $this->boundChat($org, $team, 600081);

        $this->actingAs($manager)->putJson("/api/v1/teams/{$team->id}/notification-settings/sync", [
            'event_type' => 'meeting_agenda', 'channel_type' => 'telegram', 'chat_ids' => [$a->id],
        ])->assertStatus(200);

        $this->actingAs($manager)->putJson("/api/v1/teams/{$team->id}/notification-settings/set-minutes-before", [
            'event_type' => 'meeting_agenda', 'channel_type' => 'telegram', 'minutes_before' => 90,
        ])->assertStatus(200);

        // Clearing the lead time (null) must persist null ("use default"), not 500.
        $this->actingAs($manager)->putJson("/api/v1/teams/{$team->id}/notification-settings/set-minutes-before", [
            'event_type' => 'meeting_agenda', 'channel_type' => 'telegram', 'minutes_before' => null,
        ])->assertStatus(200);

        $this->assertNull(TeamNotificationSetting::where('team_id', $team->id)
            ->where('event_type', 'meeting_agenda')->where('notifiable_id', $a->id)->value('minutes_before'));
    }

    #[Test]
    public function sync_new_chat_inherits_event_disabled_state(): void
    {
        [$org, $team, $manager] = $this->context();
        $a = $this->boundChat($org, $team, 600091);
        $b = $this->boundChat($org, $team, 600092);

        $sync = fn (array $ids) => $this->actingAs($manager)->putJson(
            "/api/v1/teams/{$team->id}/notification-settings/sync",
            ['event_type' => 'meeting_summary', 'channel_type' => 'telegram', 'chat_ids' => $ids],
        );

        $sync([$a->id])->assertStatus(200);

        // Disable the whole event, then add a second chat — the new row must inherit disabled,
        // not silently re-enable sending to chat B.
        $this->actingAs($manager)->putJson("/api/v1/teams/{$team->id}/notification-settings/set-enabled", [
            'event_type' => 'meeting_summary', 'channel_type' => 'telegram', 'enabled' => false,
        ])->assertStatus(200);

        $sync([$a->id, $b->id])->assertStatus(200);

        $this->assertSame(0, TeamNotificationSetting::where('team_id', $team->id)
            ->where('event_type', 'meeting_summary')->where('enabled', true)->count());
    }

    #[Test]
    public function deleting_a_chat_removes_its_notification_settings(): void
    {
        [$org, $team, $manager] = $this->context();
        $a = $this->boundChat($org, $team, 600101);

        $this->actingAs($manager)->putJson("/api/v1/teams/{$team->id}/notification-settings/sync", [
            'event_type' => 'meeting_summary', 'channel_type' => 'telegram', 'chat_ids' => [$a->id],
        ])->assertStatus(200);

        $this->actingAs($manager)
            ->deleteJson("/api/v1/telegram/chats/{$a->id}")
            ->assertStatus(200);

        $this->assertSame(0, TeamNotificationSetting::where('notifiable_id', $a->id)->count());
    }

    #[Test]
    public function non_manager_cannot_delete_another_orgs_chat(): void
    {
        [$org, $team] = $this->context();
        $chat = $this->boundChat($org, $team, 600111);

        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->deleteJson("/api/v1/telegram/chats/{$chat->id}")
            ->assertStatus(422); // tenant scope validation rejects

        $this->assertNotNull($chat->fresh());
    }

    #[Test]
    public function employee_cannot_read_or_change_notifications(): void
    {
        [$org, $team] = $this->context();
        $employee = User::factory()->create();
        $org->users()->attach($employee->id, ['role' => 'employee']);
        // Realistic: every org member is also in the default team. The OLD policy
        // (viewAny = isTeamMember) would leak to this employee; the tightened policy denies.
        $team->users()->attach($employee->id);

        // Denied either as 403 (policy) or 404 (tenant info-hiding) — both mean "no access".
        $denied = [403, 404];

        $read = $this->actingAs($employee)
            ->getJson("/api/v1/teams/{$team->id}/notification-settings");
        $this->assertContains($read->status(), $denied);

        $write = $this->actingAs($employee)
            ->putJson("/api/v1/teams/{$team->id}/notification-settings/sync", [
                'event_type' => 'meeting_summary', 'channel_type' => 'telegram', 'chat_ids' => [],
            ]);
        $this->assertContains($write->status(), $denied);
    }
}
