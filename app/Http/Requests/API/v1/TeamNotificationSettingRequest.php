<?php

namespace App\Http\Requests\API\v1;

use App\Http\Requests\API\ApiResourceRequest;

class TeamNotificationSettingRequest extends ApiResourceRequest
{
    public function storeRules(): array
    {
        return [
            'event_type'                   => ['required', 'string', 'max:100'],
            'channel_type'                 => ['required', 'string', 'max:100'],
            'telegram_chat_registration_id' => ['required_if:channel_type,telegram', 'nullable', 'integer', 'exists:telegram_chat_registrations,id'],
            'enabled'                      => ['sometimes', 'boolean'],
        ];
    }

    public function updateRules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
        ];
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

    public function isEnabled(): bool
    {
        return $this->boolean('enabled', true);
    }
}
