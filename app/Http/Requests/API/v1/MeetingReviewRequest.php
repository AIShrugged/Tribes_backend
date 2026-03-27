<?php

namespace App\Http\Requests\API\v1;

use Illuminate\Foundation\Http\FormRequest;

class MeetingReviewRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'calendar_event_id' => ['required', 'integer', 'exists:calendar_events,id'],
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
