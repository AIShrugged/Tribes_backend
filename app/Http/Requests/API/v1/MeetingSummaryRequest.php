<?php

namespace App\Http\Requests\API\v1;

use Illuminate\Foundation\Http\FormRequest;

class MeetingSummaryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'calendar_event_id' => ['required', 'integer', 'exists:calendar_events,id'],
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'calendar_event_id' => [
                'description' => 'Calendar event ID. This value is taken from the route parameter and does not need to be sent in the request body.',
                'example' => 123,
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['calendar_event_id' => $this->route('calendar_event_id')]);
    }

    public function getCalendarEventId(): int
    {
        return $this->input('calendar_event_id');
    }
}
