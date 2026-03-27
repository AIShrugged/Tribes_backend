<?php

namespace App\Http\Resources\API\v1;

use App\Models\Followup;
use App\Services\Followup\FollowupArtifactStateService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FollowupResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Followup $followup */
        $followup = $this->resource;

        return [
            'id'              => $followup->id,
            'calendar_event'  => CalendarEventResource::make($followup->calendarEvent),
            'team_id'         => $followup->team_id,
            'user'            => UserResource::make($followup->user),
            'methodology_id'  => $followup->methodology_id,
            'is_deprecated'   => $followup->team && $followup->methodology_id !== $followup->team->methodology_id,
            'text'            => app(FollowupArtifactStateService::class)->toState($followup),
            'status'          => $followup->status,
            'created_at'      => $followup->created_at,
            'updated_at'      => $followup->updated_at,
        ];
    }
}
