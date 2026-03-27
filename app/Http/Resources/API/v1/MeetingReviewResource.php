<?php

namespace App\Http\Resources\API\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeetingReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'calendar_event_id' => $this->calendar_event_id,
            'status'            => $this->status,
            'score'             => $this->score,
            'score_breakdown'   => $this->score_breakdown ?? [],
            'key_insight'       => $this->key_insight,
            'suggestions'       => $this->suggestions ?? [],
            'agenda_analysis'   => $this->agenda_analysis,
            'participation'               => $this->participation ?? [],
            'trend'                       => $this->trend,
            'previous_suggestions_check'  => $this->previous_suggestions_check ?? [],
            'created_at'                  => $this->created_at,
            'updated_at'                  => $this->updated_at,
        ];
    }
}
