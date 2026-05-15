<?php

namespace App\Http\Requests\API\v1;

use App\Traits\PaginatedRequestTrait;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

class CalendarEventRequest extends FormRequest
{
    use PaginatedRequestTrait;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [];

        if ($this->route()->getName() === 'calendar-events.index') {
            $rules['offset']         = ['nullable', 'integer', 'min:0'];
            $rules['limit']          = ['nullable', 'integer', 'min:1', 'max:50'];
            $rules['date']           = ['nullable', 'date_format:Y-m-d'];
            $rules['scope']          = ['nullable', 'string', 'in:past,upcoming'];
            $rules['team_id']        = ['nullable', 'integer', 'exists:teams,id'];
            $rules['user_id']        = ['nullable', 'integer', 'exists:users,id'];
            $rules['participant_id'] = ['nullable', 'integer', 'exists:participants,id'];
        }

        if ($this->route()->getName() === 'calendar-events.show') {
            $rules['calendar_event_id'] = ['required', 'integer', 'exists:calendar_events,id'];
        }

        return $rules;
    }

    protected function prepareForValidation()
    {
        $this->merge(['calendar_event_id' => $this->route('calendar_event_id')]);
    }

    public function getEventId(): int
    {
        return $this->input('calendar_event_id');
    }

    public function getScope(): ?string
    {
        return $this->input('scope');
    }

    public function getTeamId(): ?int
    {
        return $this->validated('team_id') ? (int) $this->validated('team_id') : null;
    }

    public function getParticipantId(): ?int
    {
        return $this->validated('participant_id') ? (int) $this->validated('participant_id') : null;
    }

    public function getUserId(): ?int
    {
        return $this->validated('user_id') ? (int) $this->validated('user_id') : null;
    }

    public function getDate(): ?Carbon
    {
        $date = $this->input('date');

        return $date ? Carbon::createFromFormat('Y-m-d', $date, config('app.timezone')) : null;
    }

    public function queryParameters(): array
    {
        return array_merge(parent::queryParameters(), [
            'date' => [
                'description' => 'Filter calendar events by a single day in YYYY-MM-DD format, using application timezone.',
                'example' => '2026-04-06',
            ],
        ]);
    }
}
