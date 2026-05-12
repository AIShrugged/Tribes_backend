<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Jobs\GenerateAgendaJob;
use App\Models\CalendarEvent;
use App\Models\MeetingAgenda;
use Illuminate\Support\Facades\Auth;

class AgendaController extends Controller
{
    public function index(int $calendarEventId): ApiResponse
    {
        $event = CalendarEvent::owned(Auth::id())->findOrFail($calendarEventId);

        $agendas = $event->agendas()
            ->where(function ($q) {
                $q->where('type', 'general')
                    ->orWhere(function ($q) {
                        $q->where('type', 'personal')
                            ->where('user_id', Auth::id());
                    });
            })
            ->get();

        return ApiResponse::list($agendas, $agendas->count());
    }

    public function show(int $calendarEventId, MeetingAgenda $agenda): ApiResponse
    {
        $event = CalendarEvent::owned(Auth::id())->findOrFail($calendarEventId);

        if ($agenda->calendar_event_id !== $event->id) {
            return ApiResponse::notFound();
        }

        if ($agenda->isPersonal() && $agenda->user_id !== Auth::id()) {
            return ApiResponse::error('Forbidden', status: 403);
        }

        return ApiResponse::success(data: $agenda);
    }

    public function generate(int $calendarEventId): ApiResponse
    {
        $event = CalendarEvent::owned(Auth::id())->findOrFail($calendarEventId);

        GenerateAgendaJob::dispatch($event);

        return ApiResponse::success('Agenda generation started', status: 202);
    }

    public function myAgendas(): ApiResponse
    {
        $agendas = MeetingAgenda::query()
            ->where(function ($q) {
                $q->where(function ($q) {
                    $q->where('type', 'personal')
                        ->where('user_id', Auth::id());
                })->orWhere('type', 'general');
            })
            ->whereHas('calendarEvent', fn ($q) => $q->where('starts_at', '>=', now()))
            ->with('calendarEvent:id,title,starts_at,ends_at')
            ->join('calendar_events', 'meeting_agendas.calendar_event_id', '=', 'calendar_events.id')
            ->orderBy('calendar_events.starts_at')
            ->select('meeting_agendas.*')
            ->get();

        return ApiResponse::list($agendas, $agendas->count());
    }
}
