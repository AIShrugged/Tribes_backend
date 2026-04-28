<?php

namespace App\Http\Resources\API\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DecisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'text'             => $this->text,
            'topic'            => $this->topic,
            'source_type'      => $this->source_type instanceof \BackedEnum
                ? $this->source_type->value
                : $this->source_type,
            'team_id'          => $this->team_id,
            'organization_id'  => $this->organization_id,
            'calendar_event_id' => $this->calendar_event_id,
            'summary_id'       => $this->summary_id,
            'author_raw_name'  => $this->author_raw_name,
            'author'           => $this->whenLoaded('authorUser', fn () => [
                'id'    => $this->authorUser->id,
                'name'  => $this->authorUser->name,
                'email' => $this->authorUser->email,
            ]),
            'calendar_event'   => $this->whenLoaded('calendarEvent', fn () => [
                'id'        => $this->calendarEvent->id,
                'title'     => $this->calendarEvent->title,
                'starts_at' => $this->calendarEvent->starts_at,
            ]),
            'issues'           => $this->whenLoaded('issues', fn () =>
                $this->issues->map(fn ($issue) => [
                    'id'   => $issue->id,
                    'name' => $issue->name,
                ])
            ),
            'created_at'       => $this->created_at,
            'updated_at'       => $this->updated_at,
        ];
    }
}