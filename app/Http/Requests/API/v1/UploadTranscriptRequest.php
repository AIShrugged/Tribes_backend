<?php

namespace App\Http\Requests\API\v1;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

class UploadTranscriptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * If the user belongs to exactly one team and no team_id was supplied,
     * auto-fill it. The frontend can omit the team selector entirely for
     * single-team users.
     */
    protected function prepareForValidation(): void
    {
        if (!$this->filled('calendar_event_id') && !$this->filled('team_id') && $this->user()) {
            $teams = $this->user()->teams()->limit(2)->get();
            if ($teams->count() === 1) {
                $this->merge(['team_id' => $teams->first()->id]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:10240', // 10MB to accommodate archives; uncompressed content capped separately
            ],
            'calendar_event_id' => ['nullable', 'integer', 'exists:calendar_events,id'],
            'team_id'           => ['nullable', 'integer', 'exists:teams,id', 'required_without:calendar_event_id'],
            'title'             => ['nullable', 'string', 'max:255', 'required_without:calendar_event_id'],
            'starts_at'         => ['nullable', 'date', 'before:+1 year', 'after:-5 years', 'required_without:calendar_event_id'],
            'ends_at'           => ['nullable', 'date', 'after_or_equal:starts_at', 'required_without:calendar_event_id'],
        ];
    }

    public function calendarEventId(): ?int
    {
        return $this->input('calendar_event_id') !== null
            ? (int) $this->input('calendar_event_id')
            : null;
    }

    public function teamId(): ?int
    {
        return $this->input('team_id') !== null
            ? (int) $this->input('team_id')
            : null;
    }

    public function title(): ?string
    {
        return $this->input('title');
    }

    public function startsAt(): ?Carbon
    {
        $value = $this->input('starts_at');
        return $value ? Carbon::parse($value) : null;
    }

    public function endsAt(): ?Carbon
    {
        $value = $this->input('ends_at');
        return $value ? Carbon::parse($value) : null;
    }

    public function bodyParameters(): array
    {
        return [
            'file'              => ['description' => 'Transcript file in any text format, or a ZIP/GZ archive containing one. Max 10MB.'],
            'calendar_event_id' => ['description' => 'Attach transcript to an existing meeting. If omitted, a new synthetic CalendarEvent is created.'],
            'team_id'           => ['description' => 'Required when creating a new event (or when the user has >1 team). Auto-selected if user has exactly one team.'],
            'title'             => ['description' => 'Required when creating a new event.'],
            'starts_at'         => ['description' => 'ISO 8601 datetime. Required when creating a new event.'],
            'ends_at'           => ['description' => 'ISO 8601 datetime. Required when creating a new event. Must be ≥ starts_at.'],
        ];
    }
}
