<?php

use App\Models\TelegramChatRegistration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "No teams" model: each org has a single logical bucket = its default ("General") team.
 * Legacy workspace Telegram chats (and their notification settings) live on real teams
 * (e.g. "Backenders"), so the default-team-scoped Notifications tab can't see them.
 *
 * This backfill consolidates every org's workspace chats + their TeamNotificationSetting rows
 * onto that org's default team. It also drops dead `meeting_tasks` settings (listener removed)
 * and orphan settings whose chat no longer exists. Idempotent and collision-safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        $type = TelegramChatRegistration::class;

        DB::transaction(function () use ($type): void {
            // org_id => default team id
            $defaultTeamByOrg = DB::table('teams')
                ->where('is_default', true)
                ->pluck('id', 'organization_id');

            // 1) Move workspace (non-private) chats to their org's default team.
            $chats = DB::table('telegram_chat_registrations')
                ->whereNotNull('organization_id')
                ->where('chat_type', '!=', 'private')
                ->get(['id', 'organization_id', 'team_id']);

            foreach ($chats as $chat) {
                $defaultId = $defaultTeamByOrg[$chat->organization_id] ?? null;
                if ($defaultId === null || (int) $chat->team_id === (int) $defaultId) {
                    continue;
                }

                DB::table('telegram_chat_registrations')
                    ->where('id', $chat->id)
                    ->update(['team_id' => $defaultId]);
            }

            // 2) Drop dead `meeting_tasks` settings (event has no listener).
            DB::table('team_notification_settings')->where('event_type', 'meeting_tasks')->delete();

            // 3) Drop orphan settings whose chat registration no longer exists.
            $existingChatIds = DB::table('telegram_chat_registrations')->pluck('id')->all();
            DB::table('team_notification_settings')
                ->where('notifiable_type', $type)
                ->when($existingChatIds !== [], fn ($q) => $q->whereNotIn('notifiable_id', $existingChatIds))
                ->when($existingChatIds === [], fn ($q) => $q->whereRaw('1 = 1'))
                ->delete();

            // 4) Re-point each remaining setting to its chat's (now default) team, deduping
            //    against unique(team_id, event_type, notifiable_type, notifiable_id).
            $chatTeam = DB::table('telegram_chat_registrations')->pluck('team_id', 'id');
            $settings = DB::table('team_notification_settings')
                ->where('notifiable_type', $type)
                ->get(['id', 'team_id', 'event_type', 'notifiable_id']);

            foreach ($settings as $setting) {
                $targetTeam = $chatTeam[$setting->notifiable_id] ?? null;
                if ($targetTeam === null || (int) $setting->team_id === (int) $targetTeam) {
                    continue;
                }

                $collision = DB::table('team_notification_settings')
                    ->where('team_id', $targetTeam)
                    ->where('event_type', $setting->event_type)
                    ->where('notifiable_type', $type)
                    ->where('notifiable_id', $setting->notifiable_id)
                    ->where('id', '!=', $setting->id)
                    ->exists();

                if ($collision) {
                    DB::table('team_notification_settings')->where('id', $setting->id)->delete();
                } else {
                    DB::table('team_notification_settings')
                        ->where('id', $setting->id)
                        ->update(['team_id' => $targetTeam]);
                }
            }
        });
    }

    public function down(): void
    {
        // Data backfill — original per-team assignments are not recoverable. No-op.
    }
};
