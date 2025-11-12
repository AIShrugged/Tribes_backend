<?php

namespace App\Http\Requests\API\v1;

use App\Traits\PaginatedRequestTrait;
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
            $rules['offset'] = ['nullable', 'integer', 'min:0'];
            $rules['limit'] = ['nullable', 'integer', 'min:1', 'max:50'];
        }

        if ($this->route()->getName() === 'calendar-events.show') {
            $rules['event_id'] = ['required', 'integer', 'exists:calendar_events,id'];
        }

        return $rules;
    }

    protected function prepareForValidation()
    {
        $this->merge(['event_id' => $this->route('event_id')]);
    }

    public function getEventId(): int
    {
        return $this->input('event_id');
    }
}
