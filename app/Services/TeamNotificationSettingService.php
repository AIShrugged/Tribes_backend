<?php

namespace App\Services;

use App\Models\Team;
use App\Models\TeamNotificationSetting;
use App\Models\TelegramChatRegistration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TeamNotificationSettingService
{
    public function create(Team $team, string $eventType, string $channelType, ?int $telegramChatRegistrationId, bool $enabled): TeamNotificationSetting
    {
        if ($channelType === 'telegram' && $telegramChatRegistrationId === null) {
            throw ValidationException::withMessages([
                'telegram_chat_registration_id' => ['The telegram chat registration id field is required when channel type is telegram.'],
            ]);
        }

        $registration = TelegramChatRegistration::findOrFail($telegramChatRegistrationId);

        if ($registration->bound_at === null) {
            throw ValidationException::withMessages([
                'telegram_chat_registration_id' => ['This Telegram chat is not bound yet.'],
            ]);
        }

        if ((int) $registration->team_id !== $team->id) {
            throw ValidationException::withMessages([
                'telegram_chat_registration_id' => ['This Telegram chat does not belong to the specified team.'],
            ]);
        }

        return TeamNotificationSetting::create([
            'team_id'         => $team->id,
            'event_type'      => $eventType,
            'channel_type'    => $channelType,
            'notifiable_type' => TelegramChatRegistration::class,
            'notifiable_id'   => $registration->id,
            'enabled'         => $enabled,
        ]);
    }

    public function update(TeamNotificationSetting $setting, bool $enabled, ?int $minutesBefore): TeamNotificationSetting
    {
        $data = ['enabled' => $enabled];

        if ($minutesBefore !== null) {
            $data['minutes_before'] = $minutesBefore;
        }

        $setting->update($data);

        return $setting->refresh();
    }

    /**
     * Replace the full set of Telegram recipients for one (team, event_type) — the 1:M binding (R1/R4).
     *
     * Diff-based and atomic: removed chats are deleted, new chats are created, and EXISTING rows are
     * left untouched so their `enabled` flag (R2) and `minutes_before` survive a recipient edit.
     * Any invalid chat rolls back the whole transaction (no partial recipient set).
     *
     * @param  array<int, int|string>  $chatRegistrationIds
     * @return Collection<int, TeamNotificationSetting>
     */
    public function sync(Team $team, string $eventType, string $channelType, array $chatRegistrationIds): Collection
    {
        return DB::transaction(function () use ($team, $eventType, $channelType, $chatRegistrationIds) {
            // Validate every requested chat up-front — a single bad id aborts the whole sync.
            $registrations = collect($chatRegistrationIds)
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->map(fn (int $id) => $this->resolveBoundRegistration($team, $id));

            $base = fn () => TeamNotificationSetting::query()
                ->where('team_id', $team->id)
                ->where('event_type', $eventType)
                ->where('channel_type', $channelType)
                ->where('notifiable_type', TelegramChatRegistration::class);

            $existing = $base()->get()->keyBy('notifiable_id');
            $targetIds = $registrations->pluck('id');

            // New recipients inherit the event's current enabled/minutes_before so adding a chat
            // to a disabled (or custom-lead) event stays consistent instead of silently re-enabling.
            $template = $existing->first();
            $inheritedEnabled = $template ? (bool) $template->enabled : true;
            $inheritedMinutesBefore = $template?->minutes_before;

            $toDelete = $existing->keys()
                ->reject(fn ($id) => $targetIds->contains($id))
                ->all();

            if ($toDelete !== []) {
                $base()->whereIn('notifiable_id', $toDelete)->delete();
            }

            foreach ($registrations as $registration) {
                if ($existing->has($registration->id)) {
                    continue; // untouched → preserves enabled + minutes_before (R2)
                }

                TeamNotificationSetting::create([
                    'team_id'         => $team->id,
                    'event_type'      => $eventType,
                    'channel_type'    => $channelType,
                    'notifiable_type' => TelegramChatRegistration::class,
                    'notifiable_id'   => $registration->id,
                    'enabled'         => $inheritedEnabled,
                    'minutes_before'  => $inheritedMinutesBefore,
                ]);
            }

            return $team->notificationSettings()
                ->where('event_type', $eventType)
                ->where('channel_type', $channelType)
                ->with('notifiable')
                ->get();
        });
    }

    /**
     * Flip `enabled` for every recipient row of one (team, event_type) in a single statement (R2).
     * Atomic by construction — no partial half-enabled state.
     *
     * @return Collection<int, TeamNotificationSetting>
     */
    public function setEventEnabled(Team $team, string $eventType, string $channelType, bool $enabled): Collection
    {
        TeamNotificationSetting::query()
            ->where('team_id', $team->id)
            ->where('event_type', $eventType)
            ->where('channel_type', $channelType)
            ->where('notifiable_type', TelegramChatRegistration::class)
            ->update(['enabled' => $enabled]);

        return $team->notificationSettings()
            ->where('event_type', $eventType)
            ->where('channel_type', $channelType)
            ->with('notifiable')
            ->get();
    }

    /**
     * Set the lead time for every recipient row of one (team, event_type) in one statement.
     * Per-event (not per-chat) timing — atomic. Used for meeting_agenda / pre_meeting_brief.
     *
     * @return Collection<int, TeamNotificationSetting>
     */
    public function setEventMinutesBefore(Team $team, string $eventType, string $channelType, ?int $minutesBefore): Collection
    {
        TeamNotificationSetting::query()
            ->where('team_id', $team->id)
            ->where('event_type', $eventType)
            ->where('channel_type', $channelType)
            ->where('notifiable_type', TelegramChatRegistration::class)
            ->update(['minutes_before' => $minutesBefore]);

        return $team->notificationSettings()
            ->where('event_type', $eventType)
            ->where('channel_type', $channelType)
            ->with('notifiable')
            ->get();
    }

    private function resolveBoundRegistration(Team $team, int $registrationId): TelegramChatRegistration
    {
        $registration = TelegramChatRegistration::find($registrationId);

        if ($registration === null) {
            throw ValidationException::withMessages([
                'chat_ids' => ["Telegram chat #{$registrationId} not found."],
            ]);
        }

        if ($registration->bound_at === null) {
            throw ValidationException::withMessages([
                'chat_ids' => ["Telegram chat #{$registrationId} is not bound yet."],
            ]);
        }

        if ((int) $registration->team_id !== $team->id) {
            throw ValidationException::withMessages([
                'chat_ids' => ["Telegram chat #{$registrationId} does not belong to the specified team."],
            ]);
        }

        // Defense-in-depth: reject a chat from another organization regardless of team_id.
        if ((int) $registration->organization_id !== (int) $team->organization_id) {
            throw ValidationException::withMessages([
                'chat_ids' => ["Telegram chat #{$registrationId} does not belong to the team's organization."],
            ]);
        }

        return $registration;
    }
}
