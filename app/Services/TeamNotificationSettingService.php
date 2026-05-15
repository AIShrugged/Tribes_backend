<?php

namespace App\Services;

use App\Models\Team;
use App\Models\TeamNotificationSetting;
use App\Models\TelegramChatRegistration;
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
}
