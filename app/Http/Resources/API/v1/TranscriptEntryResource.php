<?php

namespace App\Http\Resources\API\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TranscriptEntryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'participant'    => ParticipantResource::make($this->participant),
            'text'           => $this->text,
            'start_relative' => $this->start_relative,
            'end_relative'   => $this->end_relative,
            'start_absolute' => $this->start_absolute,
            'end_absolute'   => $this->end_absolute,
        ];
    }
}
