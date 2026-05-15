<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\API\v1\CalendarEventDetailResource;
use App\Http\Responses\ApiResponse;
use App\Models\CalendarEvent;
use App\Models\Source;
use App\Services\Agenda\PreviousMeetingResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

class CalendarEventDetailController extends Controller
{
    public function show(int $calendar_event_id, PreviousMeetingResolver $previousMeetingResolver): ApiResponse
    {
        // Reachability mirrors TodayBriefingService::loadEvents — paths #1 (sources pivot
        // + direct source ownership), #4 (calendar_event_profile pivot), plus followup
        // ownership for legacy cases. See CLAUDE.md "Связь пользователей с CalendarEvent".
        $userId = Auth::id();
        $sourceIdsOwnedByUser = Source::query()->where('user_id', $userId)->pluck('id');

        $event = CalendarEvent::query()
            ->whereKey($calendar_event_id)
            ->where(function (Builder $builder) use ($userId, $sourceIdsOwnedByUser): void {
                $builder
                    ->whereHas('sources', fn (Builder $sources) => $sources->where('user_id', $userId))
                    ->orWhereHas('profiles', fn (Builder $profiles) => $profiles->where('user_id', $userId))
                    ->orWhereHas('followups', fn (Builder $followups) => $followups->owned($userId));

                if ($sourceIdsOwnedByUser->isNotEmpty()) {
                    $builder->orWhereIn('source_id', $sourceIdsOwnedByUser);
                }
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
