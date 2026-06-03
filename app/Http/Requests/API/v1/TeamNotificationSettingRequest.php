<?php

namespace App\Http\Requests\API\v1;

use App\Http\Requests\API\ApiResourceRequest;

class TeamNotificationSettingRequest extends ApiResourceRequest
{
    /**
     * Telegram event types configurable from the Notifications UI.
     * `meeting_tasks` is intentionally excluded — its listener is dead (event removed).
     */
    public const ALLOWED_EVENT_TYPES = [
        'meeting_summary',
        'meeting_review',
        'meeting_agenda',
        'pre_meeting_brief',
        'critical_path',
    ];

    public function storeRules(): array
    {
        return [
            'event_type'                   => ['required', 'string', 'in:'.implode(',', self::ALLOWED_EVENT_TYPES)],
            'channel_type'                 => ['required', 'string', 'max:100'],
            'telegram_chat_registration_id' => ['required_if:channel_type,telegram', 'nullable', 'integer', 'exists:telegram_chat_registrations,id'],
            'enabled'                      => ['sometimes', 'boolean'],
        ];
    }

    public function updateRules(): array
    {
        return [
            'enabled'        => ['required', 'boolean'],
            'minutes_before' => ['nullable', 'integer', 'min:5', 'max:1440'],
        ];
    }

    /**
     * Replace the full recipient set for one event_type (1:M, R1/R4).
     */
    public function syncRules(): array
    {
        return [
            'event_type'   => ['required', 'string', 'in:'.implode(',', self::ALLOWED_EVENT_TYPES)],
            'channel_type' => ['required', 'string', 'in:telegram'],
            'chat_ids'     => ['present', 'array'],
            'chat_ids.*'   => ['integer', 'exists:telegram_chat_registrations,id'],
        ];
    }

    /**
     * Flip enabled for every recipient of one event_type (R2 master toggle).
     */
    public function setEnabledRules(): array
    {
        return [
            'event_type'   => ['required', 'string', 'in:'.implode(',', self::ALLOWED_EVENT_TYPES)],
            'channel_type' => ['required', 'string', 'in:telegram'],
            'enabled'      => ['required', 'boolean'],
        ];
    }

    /**
     * Set lead time for every recipient of one event_type (meeting_agenda / pre_meeting_brief).
     */
    public function setMinutesBeforeRules(): array
    {
        return [
            'event_type'     => ['required', 'string', 'in:'.implode(',', self::ALLOWED_EVENT_TYPES)],
            'channel_type'   => ['required', 'string', 'in:telegram'],
            'minutes_before' => ['nullable', 'integer', 'min:5', 'max:1440'],
        ];
    }

    public function getMinutesBefore(): ?int
    {
        return $this->input('minutes_before') !== null ? (int) $this->input('minutes_before') : null;
    }

    public function getEventType(): string
    {
        return $this->event_type;
    }

    public function getChannelType(): string
    {
        return $this->channel_type;
    }

    public function getTelegramChatRegistrationId(): ?int
    {
        return $this->telegram_chat_registration_id;
    }

    /**
     * @return array<int, int>
     */
    public function getChatRegistrationIds(): array
    {
        return array_map('intval', (array) $this->input('chat_ids', []));
    }

    public function isEnabled(): bool
    {
        return $this->boolean('enabled', true);
    }
}
