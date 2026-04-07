<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\API\v1\CalendarEventDetailResource;
use App\Http\Responses\ApiResponse;
use App\Models\CalendarEvent;
use App\Services\Agenda\PreviousMeetingResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

class CalendarEventDetailController extends Controller
{
    public function show(int $calendar_event_id, PreviousMeetingResolver $previousMeetingResolver): ApiResponse
    {
        $event = CalendarEvent::query()
            ->whereKey($calendar_event_id)
            ->where(function (Builder $builder): void {
                $builder->whereHas('sources', function (Builder $sources): void {
                    $sources->where('user_id', Auth::id());
                })->orWhereHas('followups', function (Builder $followups): void {
                    $followups->owned(Auth::id());
                });
            })
            ->with([
                'participants.profile',
                'meetingSummary',
                'meetingReview',
                'tasks.assignee',
                'agendas' => function (HasMany $agendas): void {
                    $agendas->where(function (Builder $agendaQuery): void {
                        $agendaQuery->where('type', 'general')
                            ->orWhere(function (Builder $personalAgendaQuery): void {
                                $personalAgendaQuery->where('type', 'personal')
                                    ->where('user_id', Auth::id());
                            });
                    })->with('user');
                },
            ])
            ->firstOrFail();

        $previousMeeting = $previousMeetingResolver->resolve($event);

        return ApiResponse::success(
            data: (new CalendarEventDetailResource($event, $previousMeeting))->resolve(request())
        );
    }
}
