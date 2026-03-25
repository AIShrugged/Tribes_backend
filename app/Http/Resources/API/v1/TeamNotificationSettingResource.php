<?php

namespace App\Http\Resources\API\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeamNotificationSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'team_id'      => $this->team_id,
            'event_type'   => $this->event_type,
            'channel_type' => $this->channel_type,
            'notifiable'   => $this->whenLoaded('notifiable', fn () => [
                'type' => class_basename($this->notifiable_type),
                'id'   => $this->notifiable_id,
                'data' => $this->notifiable instanceof \App\Models\TelegramChatRegistration
                    ? TelegramChatRegistrationResource::make($this->notifiable)
                    : null,
            ]),
            'enabled'      => $this->enabled,
            'created_at'   => $this->created_at,
            'updated_at'   => $this->updated_at,
        ];
    }
}
